<?php
// src/Services/OAuthService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\Facebook;
use Stevenmaguire\OAuth2\Client\Provider\Microsoft;
use Illuminate\Database\Capsule\Manager as DB;

/**
 * Handles OAuth2 login via Google, Meta (Facebook), and Microsoft.
 * Issues signed JWT session tokens after successful login.
 */
class OAuthService
{
    private const JWT_ALGO = 'HS256';

    public function __construct(
        private readonly string $appUrl,
        private readonly array  $providers, // ['google' => [...], 'meta' => [...], ...]
    ) {}

    // ──────────────────────────────────────────────────────────────────
    // Provider factory
    // ──────────────────────────────────────────────────────────────────

    public function getProvider(string $name): mixed
    {
        $cfg          = $this->providers[$name] ?? throw new \InvalidArgumentException("Unknown provider: {$name}");
        $redirectUri  = "{$this->appUrl}/auth/{$name}/callback";

        return match ($name) {
            'google'    => new Google([
                'clientId'     => $cfg['clientId'],
                'clientSecret' => $cfg['clientSecret'],
                'redirectUri'  => $redirectUri,
                'scopes'       => ['email', 'profile'],
            ]),
            'meta'      => new Facebook([
                'clientId'        => $cfg['clientId'],
                'clientSecret'    => $cfg['clientSecret'],
                'redirectUri'     => $redirectUri,
                'graphApiVersion' => 'v19.0',
            ]),
            'microsoft' => new Microsoft([
                'clientId'     => $cfg['clientId'],
                'clientSecret' => $cfg['clientSecret'],
                'redirectUri'  => $redirectUri,
            ]),
            default     => throw new \InvalidArgumentException("Unsupported provider: {$name}"),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // User upsert & JWT
    // ──────────────────────────────────────────────────────────────────

    /**
     * After successful OAuth callback, upsert the admin user and return a JWT.
     */
    public function handleCallback(string $providerName, string $code, string $state): array
    {
        $provider    = $this->getProvider($providerName);
        $accessToken = $provider->getAccessToken('authorization_code', ['code' => $code]);
        $ownerRaw    = $provider->getResourceOwner($accessToken);

        // Normalize across providers
        $profile = match ($providerName) {
            'google' => [
                'oauth_id'   => $ownerRaw->getId(),
                'name'       => $ownerRaw->getName(),
                'email'      => $ownerRaw->getEmail(),
                'avatar_url' => $ownerRaw->getAvatar(),
            ],
            'meta' => [
                'oauth_id'   => $ownerRaw->getId(),
                'name'       => $ownerRaw->getName(),
                'email'      => $ownerRaw->getEmail(),
                'avatar_url' => null,
            ],
            'microsoft' => [
                'oauth_id'   => $ownerRaw->getId(),
                'name'       => $ownerRaw->getFirstname() . ' ' . $ownerRaw->getLastname(),
                'email'      => $ownerRaw->getEmail(),
                'avatar_url' => null,
            ],
        };

        // Check domain / allow-list (optional, enforced via env)
        $allowedDomains = array_filter(explode(',', $_ENV['OAUTH_ALLOWED_EMAIL_DOMAINS'] ?? ''));
        if (!empty($allowedDomains)) {
            $emailDomain = substr(strrchr($profile['email'], '@'), 1);
            if (!in_array($emailDomain, $allowedDomains, true)) {
                throw new \RuntimeException("Email domain not allowed: {$emailDomain}");
            }
        }

        // Upsert admin_users
        $existing = DB::table('admin_users')
            ->where('oauth_provider', $providerName)
            ->where('oauth_id', $profile['oauth_id'])
            ->first();

        $now = date('Y-m-d H:i:s');

        if ($existing) {
            DB::table('admin_users')->where('id', $existing->id)->update([
                'name'          => $profile['name'],
                'avatar_url'    => $profile['avatar_url'],
                'last_login_at' => $now,
                'updated_at'    => $now,
            ]);
            $userId = $existing->id;
            $role   = $existing->role;
        } else {
            // First user becomes admin; subsequent users become moderators
            $isFirst = DB::table('admin_users')->count() === 0;
            $userId  = DB::table('admin_users')->insertGetId([
                'name'           => $profile['name'],
                'email'          => $profile['email'],
                'avatar_url'     => $profile['avatar_url'],
                'oauth_provider' => $providerName,
                'oauth_id'       => $profile['oauth_id'],
                'role'           => $isFirst ? 'admin' : 'moderator',
                'last_login_at'  => $now,
                'created_at'     => $now,
                'updated_at'     => $now,
            ]);
            $role = $isFirst ? 'admin' : 'moderator';
        }

        $jwt = $this->issueJwt($userId, $role, $profile['name'], $profile['email']);

        return [
            'token'  => $jwt,
            'user'   => [
                'id'         => $userId,
                'name'       => $profile['name'],
                'email'      => $profile['email'],
                'role'       => $role,
                'avatar_url' => $profile['avatar_url'],
            ],
        ];
    }

    /**
     * Validate a JWT and return its payload, or throw on failure.
     */
    public function verifyJwt(string $token): object
    {
        return JWT::decode($token, new Key($_ENV['APP_SECRET'], self::JWT_ALGO));
    }

    // ──────────────────────────────────────────────────────────────────

    private function issueJwt(int $userId, string $role, string $name, string $email): string
    {
        $ttl     = (int) ($_ENV['SESSION_LIFETIME'] ?? 86400);
        $payload = [
            'iss'  => $_ENV['APP_URL'],
            'iat'  => time(),
            'exp'  => time() + $ttl,
            'sub'  => $userId,
            'role' => $role,
            'name' => $name,
            'email' => $email,
        ];
        return JWT::encode($payload, $_ENV['APP_SECRET'], self::JWT_ALGO);
    }
}
