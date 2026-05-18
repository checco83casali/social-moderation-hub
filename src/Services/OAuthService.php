<?php
// src/Services/OAuthService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Firebase\JWT\JWT;
use Firebase\JWT\Key;
use League\OAuth2\Client\Provider\Google;
use League\OAuth2\Client\Provider\Facebook;
use TheNetworg\OAuth2\Client\Provider\Azure;
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
            'microsoft' => new Azure([
                'clientId'                  => $cfg['clientId'],
                'clientSecret'              => $cfg['clientSecret'],
                'redirectUri'               => $redirectUri,
                'tenant'                    => $_ENV['OAUTH_MICROSOFT_TENANT_ID'] ?? 'common',
                'defaultEndPointVersion'    => '2.0',
                'scopes'                    => ['openid', 'profile', 'email', 'User.Read'],
                'responseMode'              => 'query',
            ]),
            default     => throw new \InvalidArgumentException("Unsupported provider: {$name}"),
        };
    }

    // ──────────────────────────────────────────────────────────────────
    // User upsert & JWT
    // ──────────────────────────────────────────────────────────────────

    /**
     * After successful OAuth callback, upsert the admin user and return a JWT.
     * If a local account with the same email already exists, the OAuth identity
     * is linked to it — the user can then log in via either method.
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

        $now = date('Y-m-d H:i:s');

        // 1. Look up by OAuth identity
        $existing = DB::table('admin_users')
            ->where('oauth_provider', $providerName)
            ->where('oauth_id', $profile['oauth_id'])
            ->first();

        // 2. If not found by OAuth, try merging with an existing account by email
        //    (covers the case where the user was created locally first)
        if (!$existing) {
            $existing = DB::table('admin_users')
                ->where('email', $profile['email'])
                ->first();

            if ($existing) {
                // Link the OAuth identity to the existing account
                DB::table('admin_users')->where('id', $existing->id)->update([
                    'oauth_provider' => $providerName,
                    'oauth_id'       => $profile['oauth_id'],
                    'avatar_url'     => $profile['avatar_url'] ?? $existing->avatar_url,
                    'last_login_at'  => $now,
                    'updated_at'     => $now,
                ]);
            }
        }

        if ($existing) {
            DB::table('admin_users')->where('id', $existing->id)->update([
                'name'          => $profile['name'],
                'avatar_url'    => $profile['avatar_url'] ?? $existing->avatar_url,
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
     * Local (username + password) login.
     * Returns a JWT on success, throws on failure.
     */
    public function loginLocal(string $email, string $password): array
    {
        $user = DB::table('admin_users')
            ->where('email', $email)
            ->where('is_active', 1)
            ->first();

        if (!$user || !$user->password_hash) {
            throw new \RuntimeException('Credenziali non valide.');
        }

        if (!password_verify($password, $user->password_hash)) {
            throw new \RuntimeException('Credenziali non valide.');
        }

        DB::table('admin_users')->where('id', $user->id)->update([
            'last_login_at' => date('Y-m-d H:i:s'),
            'updated_at'    => date('Y-m-d H:i:s'),
        ]);

        $jwt = $this->issueJwt($user->id, $user->role, $user->name, $user->email);

        return [
            'token' => $jwt,
            'user'  => [
                'id'         => $user->id,
                'name'       => $user->name,
                'email'      => $user->email,
                'role'       => $user->role,
                'avatar_url' => $user->avatar_url,
            ],
        ];
    }

    /**
     * Set or update the password for an existing user.
     * Can be called both by the user themselves (from dashboard) and by an admin.
     * Throws if the new password is too short.
     */
    public function setPassword(int $userId, string $newPassword): void
    {
        if (mb_strlen($newPassword) < 8) {
            throw new \RuntimeException('La password deve essere di almeno 8 caratteri.');
        }

        $hash = password_hash($newPassword, PASSWORD_BCRYPT);

        $affected = DB::table('admin_users')
            ->where('id', $userId)
            ->update([
                'password_hash' => $hash,
                'updated_at'    => date('Y-m-d H:i:s'),
            ]);

        if (!$affected) {
            throw new \RuntimeException('Utente non trovato.');
        }
    }

    /**
     * Returns true if the given user already has a local password set.
     */
    public function hasPassword(int $userId): bool
    {
        return (bool) DB::table('admin_users')
            ->where('id', $userId)
            ->value('password_hash');
    }

    /**
     * Create a brand-new local-only user with a password (no OAuth).
     * Used by admin to create moderator accounts.
     */
    public function setPasswordForNewUser(string $name, string $email, string $password, string $role): int
    {
        if (mb_strlen($password) < 8) {
            throw new \RuntimeException('La password deve essere di almeno 8 caratteri.');
        }

        $hash = password_hash($password, PASSWORD_BCRYPT);
        $now  = date('Y-m-d H:i:s');

        return (int) DB::table('admin_users')->insertGetId([
            'name'          => $name,
            'email'         => $email,
            'password_hash' => $hash,
            'role'          => $role,
            'is_active'     => 1,
            'created_at'    => $now,
            'updated_at'    => $now,
        ]);
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