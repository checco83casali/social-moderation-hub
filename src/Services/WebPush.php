<?php
// src/Services/WebPush.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Firebase\JWT\JWT;
use GuzzleHttp\Client;
use GuzzleHttp\Exception\GuzzleException;

/**
 * Web Push minimale senza dipendenze extra: cifratura del payload (RFC 8291,
 * aes128gcm) + autenticazione VAPID (RFC 8292), con solo openssl e firebase/php-jwt.
 *
 * Le chiavi VAPID si generano al primo uso e vivono in storage/push/ (fuori da
 * git e dalla docroot, come le icone): un deploy non le tocca. In alternativa
 * VAPID_PRIVATE_PEM / VAPID_PUBLIC nel .env.
 */
final class WebPush
{
    /** Solo i push service dei browser: il server non deve fare richieste verso host arbitrari. */
    private const ALLOWED_HOST_SUFFIXES = [
        '.googleapis.com', '.push.services.mozilla.com', '.push.apple.com',
        '.notify.windows.com', '.mozaws.net',
    ];

    private ?string $privatePem = null;
    private ?string $publicRaw  = null;

    public function __construct(
        private readonly ?string $storageDir = null,
        private readonly ?Client $http = null,
    ) {}

    // ── Endpoint ────────────────────────────────────────────────────
    public static function isAllowedEndpoint(string $endpoint): bool
    {
        $p = parse_url($endpoint);
        if (!is_array($p) || ($p['scheme'] ?? '') !== 'https' || empty($p['host']) || isset($p['port']) && $p['port'] !== 443) {
            return false;
        }
        $host = strtolower($p['host']);
        foreach (self::ALLOWED_HOST_SUFFIXES as $suffix) {
            if (str_ends_with($host, $suffix) || $host === ltrim($suffix, '.')) {
                return true;
            }
        }
        return false;
    }

    // ── Chiavi VAPID ────────────────────────────────────────────────
    /** Chiave pubblica per applicationServerKey (base64url, 65 byte non compressi). */
    public function publicKey(): string
    {
        $this->loadKeys();
        return self::b64u((string) $this->publicRaw);
    }

    private function loadKeys(): void
    {
        if ($this->privatePem !== null) {
            return;
        }
        $envPem = $_ENV['VAPID_PRIVATE_PEM'] ?? '';
        if ($envPem !== '') {
            $this->privatePem = str_replace('\n', "\n", $envPem);
            $this->publicRaw  = self::pointFromPem($this->privatePem);
            return;
        }

        $dir  = $this->storageDir ?? dirname(__DIR__, 2) . '/storage/push';
        $file = $dir . '/vapid-private.pem';
        if (!is_file($file)) {
            if (!is_dir($dir) && !@mkdir($dir, 0750, true) && !is_dir($dir)) {
                throw new \RuntimeException('Impossibile creare ' . $dir);
            }
            $key = openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
            if ($key === false || !openssl_pkey_export($key, $pem)) {
                throw new \RuntimeException('Generazione chiavi VAPID fallita');
            }
            if (file_put_contents($file, $pem, LOCK_EX) === false) {
                throw new \RuntimeException('Impossibile salvare le chiavi VAPID');
            }
            @chmod($file, 0600);
        }
        $this->privatePem = (string) file_get_contents($file);
        $this->publicRaw  = self::pointFromPem($this->privatePem);
    }

    private static function pointFromPem(string $pem): string
    {
        $key = openssl_pkey_get_private($pem);
        $ec  = $key ? (openssl_pkey_get_details($key)['ec'] ?? null) : null;
        if (!$ec) {
            throw new \RuntimeException('Chiave VAPID non valida');
        }
        return "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);
    }

    // ── Invio ───────────────────────────────────────────────────────
    /**
     * @param array{endpoint:string,p256dh:string,auth:string} $sub
     * @return int  codice HTTP del push service (201 = accettato; 404/410 = iscrizione scaduta)
     */
    public function send(array $sub, string $payload, string $urgency = 'normal', int $ttl = 3600): int
    {
        $this->loadKeys();
        $body = self::encrypt($payload, self::b64uDecode($sub['p256dh']), self::b64uDecode($sub['auth']));

        $p   = parse_url($sub['endpoint']);
        $aud = $p['scheme'] . '://' . $p['host'];
        $jwt = JWT::encode([
            'aud' => $aud,
            'exp' => time() + 12 * 3600,
            'sub' => $_ENV['VAPID_SUBJECT'] ?? ('mailto:noreply@' . (parse_url($_ENV['APP_URL'] ?? '', PHP_URL_HOST) ?: 'localhost')),
        ], (string) $this->privatePem, 'ES256');

        $client = $this->http ?? new Client(['timeout' => 10, 'http_errors' => false, 'allow_redirects' => false]);
        try {
            $res = $client->post($sub['endpoint'], [
                'headers' => [
                    'Authorization'    => 'vapid t=' . $jwt . ', k=' . self::b64u((string) $this->publicRaw),
                    'Content-Encoding' => 'aes128gcm',
                    'Content-Type'     => 'application/octet-stream',
                    'TTL'              => (string) $ttl,
                    'Urgency'          => $urgency,
                ],
                'body' => $body,
            ]);
            return $res->getStatusCode();
        } catch (GuzzleException) {
            return 0;
        }
    }

    // ── Cifratura RFC 8291 ──────────────────────────────────────────
    /**
     * @param string      $uaPublic   chiave pubblica del browser (65 byte)
     * @param string      $authSecret segreto auth del browser (16 byte)
     * @param string|null $fixedEphemeralPem / $fixedSalt  solo per i test con i vettori dell'RFC
     */
    public static function encrypt(string $payload, string $uaPublic, string $authSecret, ?string $fixedEphemeralPem = null, ?string $fixedSalt = null): string
    {
        $eph = $fixedEphemeralPem !== null
            ? openssl_pkey_get_private($fixedEphemeralPem)
            : openssl_pkey_new(['curve_name' => 'prime256v1', 'private_key_type' => OPENSSL_KEYTYPE_EC]);
        if ($eph === false) {
            throw new \RuntimeException('Chiave effimera non valida');
        }
        $ec       = openssl_pkey_get_details($eph)['ec'];
        $asPublic = "\x04" . str_pad($ec['x'], 32, "\0", STR_PAD_LEFT) . str_pad($ec['y'], 32, "\0", STR_PAD_LEFT);

        $peer = openssl_pkey_get_public(self::spkiPem($uaPublic));
        if ($peer === false) {
            throw new \RuntimeException('Chiave pubblica del client non valida');
        }
        $shared = openssl_pkey_derive($peer, $eph, 32);
        if ($shared === false) {
            throw new \RuntimeException('ECDH fallito');
        }

        $ikm   = hash_hkdf('sha256', $shared, 32, "WebPush: info\0" . $uaPublic . $asPublic, $authSecret);
        $salt  = $fixedSalt ?? random_bytes(16);
        $cek   = hash_hkdf('sha256', $ikm, 16, "Content-Encoding: aes128gcm\0", $salt);
        $nonce = hash_hkdf('sha256', $ikm, 12, "Content-Encoding: nonce\0", $salt);

        $tag    = '';
        $cipher = openssl_encrypt($payload . "\x02", 'aes-128-gcm', $cek, OPENSSL_RAW_DATA, $nonce, $tag, '', 16);
        if ($cipher === false) {
            throw new \RuntimeException('Cifratura fallita');
        }
        return $salt . pack('N', 4096) . chr(strlen($asPublic)) . $asPublic . $cipher . $tag;
    }

    private static function spkiPem(string $point): string
    {
        $der = hex2bin('3059301306072a8648ce3d020106082a8648ce3d030107034200') . $point;
        return "-----BEGIN PUBLIC KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END PUBLIC KEY-----\n";
    }

    /** PEM EC privata (SEC1) da scalare + punto: serve ai test con i vettori dell'RFC. */
    public static function privatePemFromRaw(string $d, string $point): string
    {
        $der = hex2bin('30770201010420') . $d . hex2bin('a00a06082a8648ce3d030107a144034200') . $point;
        return "-----BEGIN EC PRIVATE KEY-----\n" . chunk_split(base64_encode($der), 64, "\n") . "-----END EC PRIVATE KEY-----\n";
    }

    public static function b64u(string $bin): string
    {
        return rtrim(strtr(base64_encode($bin), '+/', '-_'), '=');
    }

    public static function b64uDecode(string $s): string
    {
        return (string) base64_decode(strtr($s, '-_', '+/') . str_repeat('=', (4 - strlen($s) % 4) % 4), true);
    }
}
