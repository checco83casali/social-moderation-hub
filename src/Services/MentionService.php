<?php
// src/Services/MentionService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Menzioni di una pagina collegata sotto post di altre pagine (evento webhook `mention`).
 *
 * Non sono moderabili (il token della pagina non può nasconderle né eliminarle), quindi
 * non entrano nella coda: fanno partire la notifica push e, con la licenza Enterprise
 * (`mentions`), vengono salvate per la lista di sola lettura "Menzioni" della dashboard.
 * Si conserva solo un estratto del testo; conservazione: RetentionService::purgeMentions().
 */
final class MentionService
{
    /** Lunghezza massima dell'estratto salvato (colonna mentions.excerpt). */
    public const EXCERPT_CHARS = 280;

    public function __construct(
        private readonly MetaGraphService $meta,
        private readonly LicenseService   $license,
    ) {}

    /** Salvataggio per la lista Menzioni: solo con licenza Enterprise. */
    public function isAvailable(): bool
    {
        return $this->license->canViewMentions();
    }

    public static function excerpt(string $text, int $chars = self::EXCERPT_CHARS): string
    {
        $text = trim((string) preg_replace('/\s+/u', ' ', $text));
        return mb_strlen($text) > $chars ? rtrim(mb_substr($text, 0, $chars)) . '…' : $text;
    }

    /**
     * Gestisce una menzione già filtrata da MetaGraphService::parseWebhookMentions().
     * Ritorna il testo del messaggio (eventualmente letto da Facebook), per il debug.
     *
     * @param array{id:string, post_id:string, page_id:string, message:string, from:array{id:string,name:string}, created_time:mixed, verb:string} $mention
     * @param array<string, mixed> $page riga di connected_pages
     */
    public function handle(array $mention, array $page): string
    {
        $message = $mention['message'];
        if ($mention['verb'] === 'remove') {
            $this->forget($mention, $page);
            return $message;
        }

        $store = $this->isAvailable();
        if ($message === '' && $store && !empty($page['page_access_token'])) {
            // Il payload non sempre porta il testo: tentativo best-effort con il token della pagina.
            $message = (string) ($this->meta->getComment($mention['id'], (string) $page['page_access_token'])['message'] ?? '');
        }

        $isNew = true;
        if ($store) {
            $isNew = $this->store($mention, $page, $message);
        }
        // Redelivery dello stesso webhook: la riga esiste già, niente seconda notifica.
        if ($mention['verb'] === 'add' && $isNew) {
            PushService::notifyLater('mention', $message);
        }
        return $message;
    }

    /**
     * Recupero (bin/backfill-mentions.php) di una menzione già ricevuta: stessa scrittura di handle(),
     * ma senza push né chiamate a Facebook e con la data di ricezione ORIGINALE dell'evento, così il
     * dato non vive oltre la finestra di conservazione. Ritorna true se la riga è nuova.
     *
     * @param array{id:string, post_id:string, page_id:string, message:string, from:array{id:string,name:string}, created_time:mixed, verb:string} $mention
     * @param array<string, mixed> $page
     */
    public function backfill(array $mention, array $page, string $receivedAt): bool
    {
        if ($mention['verb'] === 'remove') {
            $this->forget($mention, $page);
            return false;
        }
        return $this->store($mention, $page, $mention['message'], $receivedAt);
    }

    /**
     * @param array{id:string, post_id:string, page_id:string, message:string, from:array{id:string,name:string}, created_time:mixed, verb:string} $mention
     * @param array<string, mixed> $page
     */
    private function store(array $mention, array $page, string $message, ?string $receivedAt = null): bool
    {
        $created = $mention['created_time'];
        $ts      = is_numeric($created) ? (int) $created : (strtotime((string) $created) ?: time());
        $row = [
            'social_user_id'   => $this->socialUserId($mention['from']['id'], $mention['from']['name']),
            'platform_post_id' => $mention['post_id'],
            'excerpt'          => $message !== '' ? self::excerpt($message) : null,
            'mentioned_at'     => date('Y-m-d H:i:s', $ts),
            'received_at'      => $receivedAt ?? date('Y-m-d H:i:s'),
        ];
        $key = ['page_id' => (int) $page['id'], 'platform_comment_id' => $mention['id']];
        try {
            $existing = DB::table('mentions')->where($key)->first();
            if (!$existing) {
                DB::table('mentions')->insert($key + $row);
                return true;
            }
            // Evento ripetuto/edit (spesso senza message/from): non sovrascrivere i dati già salvati con valori vuoti.
            $upd = ['received_at' => $row['received_at']];
            if ($row['excerpt'] !== null) {
                $upd['excerpt'] = $row['excerpt'];
            }
            if ($row['social_user_id'] !== null) {
                $upd['social_user_id'] = $row['social_user_id'];
            }
            DB::table('mentions')->where($key)->update($upd);
            return false;
        } catch (\Throwable) {
            // tabella non ancora migrata: la menzione resta solo nel debug del webhook
            return true;
        }
    }

    /**
     * @param array{id:string, post_id:string, page_id:string, message:string, from:array{id:string,name:string}, created_time:mixed, verb:string} $mention
     * @param array<string, mixed> $page
     */
    private function forget(array $mention, array $page): void
    {
        try {
            DB::table('mentions')->where('page_id', (int) $page['id'])
                ->where('platform_comment_id', $mention['id'])->delete();
        } catch (\Throwable) {}
    }

    /** Autore come utente social (stessa identità e stessa conservazione degli altri commentatori). */
    private function socialUserId(string $platformUserId, string $name): ?int
    {
        if ($platformUserId === '') {
            return null;
        }
        $user = DB::table('social_users')->where('platform', 'facebook')
            ->where('platform_user_id', $platformUserId)->first();
        if ($user) {
            if ($name !== '' && empty($user->display_name)) {
                DB::table('social_users')->where('id', $user->id)->update([
                    'display_name' => $name,
                    'updated_at'   => date('Y-m-d H:i:s'),
                ]);
            }
            return (int) $user->id;
        }
        return (int) DB::table('social_users')->insertGetId([
            'platform'         => 'facebook',
            'platform_user_id' => $platformUserId,
            'display_name'     => $name !== '' ? $name : null,
            'ban_status'       => 'clean',
            'violation_count'  => 0,
            'created_at'       => date('Y-m-d H:i:s'),
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);
    }
}
