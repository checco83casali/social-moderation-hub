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
    /** @var array<string, true> */
    private static array $pending = [];
    private static bool $hooked = false;

    public function __construct(private readonly ?WebPush $webPush = null) {}

    /**
     * Accoda la notifica e la invia a risposta già consegnata: il webhook di Meta
     * deve rispondere subito e non aspettare i push service dei browser.
     */
    public static function notifyLater(string $kind): void
    {
        self::$pending[$kind] = true;
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
            // La segnalazione ha la precedenza: se ci sono entrambe, un solo avviso.
            foreach (isset(self::$pending['reportable']) ? ['reportable'] : array_keys(self::$pending) as $kind) {
                try {
                    $svc->notify($kind);
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

    /** Invia a tutti i dispositivi idonei; ritorna quanti push sono stati accettati. */
    public function notify(string $kind): int
    {
        $c = $this->counts();
        if ($kind === 'reportable') {
            $payload = [
                'title' => '⚠ Nuova segnalazione',
                'body'  => 'Contenuto potenzialmente illegale da valutare. In attesa: ' . $c['reportable'] . ' segnalazioni, ' . $c['queue'] . ' in coda.',
                'tag'   => 'reportable',
                'url'   => '/dashboard.html?screen=reportable',
                'urgent' => true,
            ];
        } else {
            $payload = [
                'title' => 'Nuovo commento da rivedere',
                'body'  => $c['queue'] . ' in coda' . ($c['reportable'] ? ' · ' . $c['reportable'] . ' segnalazioni' : ''),
                'tag'   => 'queue',
                'url'   => '/dashboard.html?screen=queue',
                'urgent' => false,
            ];
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
            ['title' => 'Notifiche attive ✓', 'body' => 'Riceverai avvisi per commenti in coda e segnalazioni.', 'tag' => 'test', 'url' => '/dashboard.html', 'urgent' => false, 'badge' => $c['total']],
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
