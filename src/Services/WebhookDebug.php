<?php
// src/Services/WebhookDebug.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Interruttore "debug webhook": mentre è attivo il webhook salva anche gli
 * eventi con firma non valida e i dettagli diagnostici (header, IP, esito).
 *
 * Il payload contiene dati personali di terzi, quindi il debug si spegne da
 * solo: `webhook_debug_until` è un timestamp UNIX di scadenza (0 = spento).
 */
final class WebhookDebug
{
    public const KEY          = 'webhook_debug_until';
    public const DURATION_SEC = 7200; // 2 ore
    public const MAX_PAYLOAD  = 65536;

    public static function until(): int
    {
        try {
            return (int) DB::table('app_settings')->where('key', self::KEY)->value('value');
        } catch (\Throwable) {
            return 0;
        }
    }

    public static function isActive(): bool
    {
        return self::until() > time();
    }

    public static function enable(?int $userId): int
    {
        $until = time() + self::DURATION_SEC;
        self::store($until, $userId);
        return $until;
    }

    public static function disable(?int $userId): void
    {
        self::store(0, $userId);
    }

    private static function store(int $until, ?int $userId): void
    {
        DB::table('app_settings')->updateOrInsert(
            ['key' => self::KEY],
            ['value' => (string) $until, 'updated_by' => $userId],
        );
    }

    /**
     * Header utili alla diagnosi, senza credenziali.
     *
     * @param array<string, array<int, string>> $headers
     * @return array<string, string>
     */
    public static function safeHeaders(array $headers): array
    {
        $drop = ['cookie', 'authorization', 'proxy-authorization'];
        $out  = [];
        foreach ($headers as $name => $values) {
            if (in_array(strtolower((string) $name), $drop, true)) {
                continue;
            }
            $out[(string) $name] = implode(', ', $values);
        }
        return $out;
    }
}
