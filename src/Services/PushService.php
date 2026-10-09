<?php
// src/Services/PushService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Notifiche push ai moderatori: nuovo commento in coda / nuova segnalazione.
 * Il payload porta anche il totale (coda + segnalazioni) per il badge dell'icona.
 */
final class PushService
{
    /** Etichette davanti all'inizio del testo. */
    private const TAGS = ['queue' => 'Revisione', 'reportable' => 'Segnalazione', 'appeal' => 'Ricorso', 'test' => 'Prova'];

    /** Lunghezza massima dell'inizio del testo mostrato nella notifica. */
    private const EXCERPT_CHARS = 90;

    /** @var array<string, true> */
    private static array $pending = [];

    /** @var array<string, string> inizio del testo dell'ultimo elemento per tipo */
    private static array $excerpts = [];
    private static bool $hooked = false;

    /** @var list<array{host:string,status:int}> esito dell'ultimo invio, per la diagnostica */
    private array $lastStatuses = [];

    public function __construct(private readonly ?WebPush $webPush = null) {}

    /** @return list<array{host:string,status:int}> */
    public function lastStatuses(): array
    {
        return $this->lastStatuses;
    }

    /**
     * Accoda la notifica e la invia a risposta già consegnata: il webhook di Meta
     * deve rispondere subito e non aspettare i push service dei browser.
     */
    public static function notifyLater(string $kind, ?string $text = null): void
    {
        self::$pending[$kind] = true;
        if ($text !== null && trim($text) !== '') {
            self::$excerpts[$kind] = $text;
        }
        if (self::$hooked) {
            return;
        }
        self::$hooked = true;
        register_shutdown_function(static function (): void {
            if (function_exists('fastcgi_finish_request')) {
                fastcgi_finish_request();
            } elseif (function_exists('litespeed_finish_request')) {
                litespeed_finish_request();
            }
            $svc = new self();
            // La segnalazione ha la precedenza sulla coda: se ci sono entrambe, un solo avviso.
            // I ricorsi hanno un avviso a parte.
            $kinds = array_keys(self::$pending);
            if (isset(self::$pending['reportable'])) {
                $kinds = array_values(array_diff($kinds, ['queue']));
            }
            foreach ($kinds as $kind) {
                try {
                    $svc->notify($kind, self::$excerpts[$kind] ?? null);
                } catch (\Throwable) {
                    // Il push è best-effort: non deve mai rompere la moderazione.
                }
            }
        });
    }

    /** @return array{queue:int, reportable:int, total:int} */
    public function counts(): array
    {
        $q = DB::table('comments')->where('status', 'escalated_human')->count();
        $r = DB::table('comments')->where('status', 'escalated_reportable')->count();
        return ['queue' => $q, 'reportable' => $r, 'total' => $q + $r];
    }

    /**
     * Titolo delle notifiche: volutamente vuoto. Il nome dell'applicazione lo mostra già
     * il sistema operativo sopra la notifica; ripeterlo nel titolo lo duplicherebbe.
     */
    private function title(): string
    {
        return '';
    }

    /** "Revisione: inizio del testo…" (una sola riga), oppure solo l'etichetta se il testo manca. */
    private function line(string $kind, ?string $text): string
    {
        $tag  = self::TAGS[$kind] ?? 'Revisione';
        $text = trim((string) preg_replace('/\s+/u', ' ', (string) $text));
        if ($text === '') {
            return $tag;
        }
        if (mb_strlen($text) > self::EXCERPT_CHARS) {
            $text = rtrim(mb_substr($text, 0, self::EXCERPT_CHARS)) . '…';
        }
        return $tag . ': ' . $text;
    }

    /** Invia a tutti i dispositivi idonei; ritorna quanti push sono stati accettati. */
    public function notify(string $kind, ?string $text = null): int
    {
        $c = $this->counts();
        $payload = [
            'title'  => $this->title(),
            'tag'    => $kind,
            'urgent' => $kind === 'reportable',
        ];
        if ($kind === 'reportable') {
            $payload['body'] = $this->line($kind, $text) . "\n" . $c['reportable'] . ' segnalazioni · ' . $c['queue'] . ' in coda';
            $payload['url']  = '/dashboard.html?screen=reportable';
        } elseif ($kind === 'appeal') {
            $pending = DB::table('appeal_records')->where('status', 'pending')->count();
            $payload['body'] = $this->line($kind, $text) . "\n" . $pending . ' ricorsi in attesa';
            $payload['url']  = '/dashboard.html?screen=appeals';
        } else {
            $payload['body'] = $this->line('queue', $text) . "\n" . $c['queue'] . ' in coda' . ($c['reportable'] ? ' · ' . $c['reportable'] . ' segnalazioni' : '');
            $payload['url']  = '/dashboard.html?screen=queue';
        }
        $payload['badge'] = $c['total'];
        return $this->sendToAll($payload, $kind === 'reportable');
    }

    /** Notifica di prova al solo utente indicato. */
    public function sendTest(int $userId): int
    {
        $c = $this->counts();
        return $this->sendPayload(
            DB::table('push_subscriptions')->where('user_id', $userId)->get()->all(),
            ['title' => $this->title(), 'body' => $this->line('test', 'Notifiche attive ✓') . "\n" . 'Riceverai avvisi per revisioni, segnalazioni e ricorsi', 'tag' => 'test', 'url' => '/dashboard.html', 'urgent' => false, 'badge' => $c['total']],
        );
    }

    /** @param array<string, mixed> $payload */
    private function sendToAll(array $payload, bool $reportableOnlyStaff): int
    {
        $q = DB::table('push_subscriptions as s')
            ->join('admin_users as u', 'u.id', '=', 's.user_id')
            ->select('s.*');
        if ($reportableOnlyStaff) {
            $q->whereIn('u.role', ['admin', 'supervisor']);
        }
        return $this->sendPayload($q->get()->all(), $payload);
    }

    /**
     * @param array<int, object> $subs
     * @param array<string, mixed> $payload
     */
    private function sendPayload(array $subs, array $payload): int
    {
        $this->lastStatuses = [];
        if (!$subs) {
            return 0;
        }
        $wp   = $this->webPush ?? new WebPush();
        $json = (string) json_encode($payload, JSON_UNESCAPED_UNICODE);
        $ok   = 0;
        foreach ($subs as $s) {
            $status = $wp->send(
                ['endpoint' => $s->endpoint, 'p256dh' => $s->p256dh, 'auth' => $s->auth],
                $json,
                !empty($payload['urgent']) ? 'high' : 'normal',
            );
            $host = (string) parse_url($s->endpoint, PHP_URL_HOST);
            $this->lastStatuses[] = ['host' => $host, 'status' => $status];
            if ($status >= 200 && $status < 300) {
                $ok++;
                DB::table('push_subscriptions')->where('id', $s->id)->update(['last_success_at' => date('Y-m-d H:i:s')]);
            } elseif ($status === 404 || $status === 410) {
                DB::table('push_subscriptions')->where('id', $s->id)->delete(); // iscrizione scaduta
            }
        }
        return $ok;
    }
}
