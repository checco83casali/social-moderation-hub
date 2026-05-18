<?php
// src/Services/LicenseService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;

/**
 * LicenseService — feature-gate engine for Social Moderation Hub.
 *
 * HOW IT WORKS
 * ─────────────────────────────────────────────────────────────────
 * 1. The admin saves a license key via Settings → Licensing.
 * 2. On first call (or after cache TTL expires) this service contacts
 *    the remote license server at LICENSE_SERVER_URL and caches the
 *    full response in the `license_cache` table.
 * 3. All other code calls isPro() or hasFeature() — never the network
 *    directly — so the gate is a single O(1) DB lookup in the hot path.
 *
 * OFFLINE RESILIENCE
 * ─────────────────────────────────────────────────────────────────
 * - If the license server is unreachable and the cache is < OFFLINE_TTL_DAYS old,
 *   the cached status is used as-is (status = 'unreachable' recorded but
 *   features remain unlocked).
 * - If the cache is older than OFFLINE_TTL_DAYS the installation falls
 *   back to 'free' gracefully (no crash, no data loss).
 *
 * PRO FEATURES (keys returned by the license server in the `features` array)
 * ─────────────────────────────────────────────────────────────────
 *   data_retention      — configurable purge of old comments (cron)
 *   export_log          — GET /api/export/moderation-log (CSV / JSON)
 *   templates           — editable hide_reply_template + hide_reportable_reply_template
 *   per_page_thresholds — per-page Haiku / Sonnet confidence thresholds
 *   fact_check          — AI fact-check draft + sources in moderation pipeline
 *
 * ADDING A NEW PRO FEATURE
 * ─────────────────────────────────────────────────────────────────
 * 1. Add its key string to KNOWN_FEATURES below (documentation only).
 * 2. Add $license->hasFeature('your_feature') check in the controller/service.
 * 3. Add it to the license server's feature list for Pro plans.
 * No code changes needed here.
 */
class LicenseService
{
    // ── Constants ────────────────────────────────────────────────────

    /**
     * Remote license server base URL. Read from LICENSE_SERVER_URL in .env.
     * Leave empty to skip remote calls entirely (treated as offline).
     */
    private function licenseServer(): string
    {
        return rtrim((string) ($_ENV['LICENSE_SERVER_URL'] ?? ''), '/');
    }

    /** Re-validate against remote server every N hours (normal operation). */
    private const REMOTE_TTL_HOURS = 24;

    /**
     * If the server is unreachable, accept cached result for up to N days
     * before falling back to free. Prevents installs breaking during
     * license-server maintenance.
     */
    private const OFFLINE_TTL_DAYS = 7;

    /** HTTP timeout (seconds) for license server calls. */
    private const HTTP_TIMEOUT = 5;

    /** Feature keys that this version of the app understands. */
    private const KNOWN_FEATURES = [
        'data_retention',
        'export_log',
        'templates',
        'per_page_thresholds',
        'fact_check',
    ];

    // ── State ────────────────────────────────────────────────────────

    /** In-process cache: resolved once per request, avoids redundant DB hits. */
    private ?array $resolvedState = null;

    public function __construct(
        private readonly ?Logger $logger = null,
    ) {}

    // ──────────────────────────────────────────────────────────────────
    // Offline mode
    // ──────────────────────────────────────────────────────────────────
    //
    // When LICENSE_OFFLINE_MODE=true in .env, the service never contacts
    // the remote license server. Features are read from LICENSE_OFFLINE_FEATURES
    // (comma-separated subset of KNOWN_FEATURES). This is intended for
    // installations that want to manage Pro features manually without
    // depending on an external licensing service.

    private function isOfflineMode(): bool
    {
        $v = strtolower(trim((string) ($_ENV['LICENSE_OFFLINE_MODE'] ?? '')));
        return in_array($v, ['1', 'true', 'yes', 'on'], true);
    }

    private function offlineState(): array
    {
        $raw = (string) ($_ENV['LICENSE_OFFLINE_FEATURES'] ?? '');
        $features = $this->sanitizeFeatures(
            array_filter(array_map('trim', explode(',', $raw)))
        );

        return [
            'status'             => empty($features) ? 'free' : 'valid',
            'plan'               => empty($features) ? '' : 'offline',
            'features'           => $features,
            'is_pro'             => !empty($features),
            'expires_at'         => null,
            'domain'             => null,
            'checked_at'         => null,
            'offline_mode'       => false, // not the same as "cache stale"
            'external_disabled'  => true,  // tells the UI not to show activate/deactivate
        ];
    }

    // ──────────────────────────────────────────────────────────────────
    // Public API
    // ──────────────────────────────────────────────────────────────────

    /**
     * Returns true when the installation has a valid Pro license with at
     * least one known Pro feature unlocked.
     */
    public function isPro(): bool
    {
        return $this->resolve()['is_pro'];
    }

    /**
     * Returns true when a specific Pro feature is unlocked.
     *
     * @param string $feature  One of the KNOWN_FEATURES keys.
     */
    public function hasFeature(string $feature): bool
    {
        $state = $this->resolve();
        return in_array($feature, $state['features'], true);
    }

    /**
     * Returns the full resolved license state for the /api/license endpoint.
     * Never exposes the raw license key; returns only status metadata.
     */
    public function getStatus(): array
    {
        $state = $this->resolve();

        return [
            'status'            => $state['status'],   // valid | invalid | expired | unreachable | free
            'plan'              => $state['plan'],
            'features'          => $state['features'],
            'is_pro'            => $state['is_pro'],
            'expires_at'        => $state['expires_at'],
            'domain'            => $state['domain'],
            'checked_at'        => $state['checked_at'],
            'offline_mode'      => $state['offline_mode'],
            'external_disabled' => $state['external_disabled'] ?? false,
        ];
    }

    /**
     * Activate a new license key: validates remotely, persists cache, updates
     * app_settings. Called by PUT /api/license.
     *
     * @return array{ok: bool, status: string, plan: string, features: list<string>, error?: string}
     */
    public function activate(string $key, string $domain, int $adminId): array
    {
        if ($this->isOfflineMode()) {
            return [
                'ok'       => false,
                'status'   => 'invalid',
                'plan'     => '',
                'features' => [],
                'error'    => 'Offline mode active — license features are managed via LICENSE_OFFLINE_FEATURES in .env',
            ];
        }

        $key    = trim($key);
        $domain = strtolower(trim($domain));

        if ($key === '') {
            return ['ok' => false, 'status' => 'invalid', 'plan' => '', 'features' => [], 'error' => 'Empty key'];
        }

        $result = $this->remoteValidate($key, $domain);

        // Persist cache regardless of outcome
        $this->persistCache($key, $result);

        // Update app_settings
        $now = date('Y-m-d H:i:s');
        $settingRows = [
            'license_key'          => $key,
            'license_status'       => $result['status'],
            'license_plan'         => $result['plan']       ?? '',
            'license_validated_at' => $now,
            'license_expires_at'   => $result['expires_at'] ?? '',
            'license_domain'       => $domain,
        ];
        foreach ($settingRows as $k => $v) {
            DB::table('app_settings')->updateOrInsert(
                ['key' => $k],
                ['value' => (string) $v, 'updated_by' => $adminId, 'updated_at' => $now]
            );
        }

        // Bust in-process cache so next isPro() call sees new state immediately
        $this->resolvedState = null;

        $ok = $result['status'] === 'valid';
        return array_merge($result, ['ok' => $ok]);
    }

    /**
     * Deactivate: clears the license key and resets to free.
     */
    public function deactivate(int $adminId): void
    {
        $now  = date('Y-m-d H:i:s');
        $keys = ['license_key', 'license_status', 'license_plan',
                 'license_validated_at', 'license_expires_at', 'license_domain'];
        foreach ($keys as $k) {
            $val = ($k === 'license_status') ? 'free' : '';
            DB::table('app_settings')->updateOrInsert(
                ['key' => $k],
                ['value' => $val, 'updated_by' => $adminId, 'updated_at' => $now]
            );
        }
        // Clear cache table for this key
        try {
            DB::table('license_cache')->truncate();
        } catch (\Throwable) {}

        $this->resolvedState = null;
    }

    /**
     * Force a remote re-check now, ignoring cache TTL.
     * Used by the "Refresh" button in the GUI.
     */
    public function forceRefresh(int $adminId): array
    {
        if ($this->isOfflineMode()) {
            return ['ok' => false, 'error' => 'Offline mode active — no remote check available'];
        }

        $key    = DB::table('app_settings')->where('key', 'license_key')->value('value') ?? '';
        $domain = DB::table('app_settings')->where('key', 'license_domain')->value('value') ?? '';

        if (trim($key) === '') {
            return ['ok' => false, 'error' => 'No license key configured'];
        }

        $result = $this->remoteValidate($key, $domain);
        $this->persistCache($key, $result);

        $now = date('Y-m-d H:i:s');
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'license_status'],
            ['value' => $result['status'], 'updated_by' => $adminId, 'updated_at' => $now]
        );
        DB::table('app_settings')->updateOrInsert(
            ['key' => 'license_validated_at'],
            ['value' => $now, 'updated_by' => $adminId, 'updated_at' => $now]
        );

        $this->resolvedState = null;
        return array_merge($result, ['ok' => $result['status'] === 'valid']);
    }

    // ──────────────────────────────────────────────────────────────────
    // Internal resolution logic
    // ──────────────────────────────────────────────────────────────────

    /**
     * Core resolver: reads license state from cache / settings.
     * Results are memoised for the lifetime of this request.
     */
    private function resolve(): array
    {
        if ($this->resolvedState !== null) {
            return $this->resolvedState;
        }

        // Offline mode: do not contact the remote license server.
        // Features come from LICENSE_OFFLINE_FEATURES.
        if ($this->isOfflineMode()) {
            $this->resolvedState = $this->offlineState();
            return $this->resolvedState;
        }

        $this->resolvedState = $this->buildFreeState();

        try {
            $key = DB::table('app_settings')->where('key', 'license_key')->value('value') ?? '';
            if (trim($key) === '') {
                return $this->resolvedState; // no key configured → free
            }

            // Check local cache freshness
            $cached = DB::table('license_cache')
                ->where('license_key', $key)
                ->orderByDesc('checked_at')
                ->first();

            $cacheAgeHours = $cached
                ? (time() - strtotime($cached->checked_at)) / 3600
                : PHP_INT_MAX;

            if ($cached && $cacheAgeHours < self::REMOTE_TTL_HOURS) {
                // Cache is fresh — use it
                $this->resolvedState = $this->stateFromCache($cached);
                return $this->resolvedState;
            }

            // Cache is stale or missing — try remote
            $domain = DB::table('app_settings')->where('key', 'license_domain')->value('value') ?? '';
            $result = $this->remoteValidate($key, $domain);
            $this->persistCache($key, $result);
            $this->resolvedState = $this->stateFromResult($result);

        } catch (\Throwable $e) {
            $this->logger?->warning('[LicenseService] resolve() error: ' . $e->getMessage());

            // Fallback: use whatever is in app_settings (persisted from last activation)
            $this->resolvedState = $this->stateFromSettings();
        }

        return $this->resolvedState;
    }

    // ──────────────────────────────────────────────────────────────────
    // Remote validation
    // ──────────────────────────────────────────────────────────────────

    /**
     * Calls the remote license server.
     * Returns a normalised result array regardless of outcome.
     *
     * @return array{status: string, plan: string, features: list<string>, expires_at: string, domain: string, raw: string}
     */
    private function remoteValidate(string $key, string $domain): array
    {
        $server = $this->licenseServer();
        if ($server === '') {
            // No remote server configured — behave like an unreachable server,
            // the caller will fall back to cache or free state.
            return [
                'status'     => 'unreachable',
                'plan'       => '',
                'features'   => [],
                'expires_at' => '',
                'domain'     => $domain,
                'raw'        => 'LICENSE_SERVER_URL is not configured',
            ];
        }

        $url = $server . '/validate?' . http_build_query([
            'key'    => $key,
            'domain' => $domain,
            'app'    => 'moderation-hub',
        ]);

        try {
            $ctx = stream_context_create([
                'http' => [
                    'method'          => 'GET',
                    'timeout'         => self::HTTP_TIMEOUT,
                    'ignore_errors'   => true,
                    'follow_location' => 1,
                    'header'          => implode("\r\n", [
                        'Accept: application/json',
                        'User-Agent: ModerationHub/1.0',
                    ]),
                ],
                'ssl' => [
                    'verify_peer'      => true,
                    'verify_peer_name' => true,
                ],
            ]);

            $raw = @file_get_contents($url, false, $ctx);

            if ($raw === false) {
                throw new \RuntimeException('Network error or timeout');
            }

            $json = json_decode($raw, true, 512, JSON_THROW_ON_ERROR);

            return [
                'status'     => $this->sanitizeStatus($json['status'] ?? 'invalid'),
                'plan'       => substr((string) ($json['plan'] ?? ''), 0, 64),
                'features'   => $this->sanitizeFeatures($json['features'] ?? []),
                'expires_at' => substr((string) ($json['expires_at'] ?? ''), 0, 32),
                'domain'     => substr((string) ($json['domain']     ?? $domain), 0, 255),
                'raw'        => substr($raw, 0, 4096),
            ];

        } catch (\Throwable $e) {
            $this->logger?->warning('[LicenseService] Remote validation failed: ' . $e->getMessage());

            // Return 'unreachable' — caller decides whether to fall back to cache
            return [
                'status'     => 'unreachable',
                'plan'       => '',
                'features'   => [],
                'expires_at' => '',
                'domain'     => $domain,
                'raw'        => $e->getMessage(),
            ];
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Cache persistence
    // ──────────────────────────────────────────────────────────────────

    private function persistCache(string $key, array $result): void
    {
        try {
            DB::table('license_cache')->insert([
                'license_key'  => $key,
                'status'       => $result['status'],
                'plan'         => $result['plan']       ?: null,
                'features'     => json_encode($result['features']),
                'expires_at'   => $result['expires_at'] ?: null,
                'domain'       => $result['domain']     ?: null,
                'raw_response' => $result['raw']        ?? null,
                'checked_at'   => date('Y-m-d H:i:s'),
                'created_at'   => date('Y-m-d H:i:s'),
            ]);

            // Keep only the last 10 cache rows per key (housekeeping)
            $ids = DB::table('license_cache')
                ->where('license_key', $key)
                ->orderByDesc('checked_at')
                ->skip(10)->take(PHP_INT_MAX)
                ->pluck('id');
            if ($ids->isNotEmpty()) {
                DB::table('license_cache')->whereIn('id', $ids)->delete();
            }
        } catch (\Throwable $e) {
            $this->logger?->warning('[LicenseService] Cache persist failed: ' . $e->getMessage());
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // State builders
    // ──────────────────────────────────────────────────────────────────

    private function buildFreeState(bool $offlineMode = false): array
    {
        return [
            'status'       => 'free',
            'plan'         => '',
            'features'     => [],
            'is_pro'       => false,
            'expires_at'   => null,
            'domain'       => null,
            'checked_at'   => null,
            'offline_mode' => $offlineMode,
        ];
    }

    private function stateFromCache(object $cached): array
    {
        $features = [];
        if (!empty($cached->features)) {
            $decoded = json_decode($cached->features, true);
            $features = is_array($decoded) ? $this->sanitizeFeatures($decoded) : [];
        }

        $status      = $this->sanitizeStatus($cached->status);
        $offlineMode = ($status === 'unreachable');

        // If unreachable but cache is within offline TTL, use last known features
        if ($offlineMode) {
            $cacheAgeDays = (time() - strtotime($cached->checked_at)) / 86400;
            if ($cacheAgeDays > self::OFFLINE_TTL_DAYS) {
                // Too old — fall back to free
                return $this->buildFreeState(offlineMode: true);
            }
            // Within offline TTL — restore last known valid features from settings
            return $this->stateFromSettings(offlineMode: true);
        }

        return [
            'status'       => $status,
            'plan'         => $cached->plan       ?? '',
            'features'     => $features,
            'is_pro'       => $status === 'valid' && !empty($features),
            'expires_at'   => $cached->expires_at ?? null,
            'domain'       => $cached->domain     ?? null,
            'checked_at'   => $cached->checked_at ?? null,
            'offline_mode' => false,
        ];
    }

    private function stateFromResult(array $result): array
    {
        $status   = $this->sanitizeStatus($result['status']);
        $features = $result['features'];

        if ($status === 'unreachable') {
            // Remote failed on fresh attempt — fall back to settings
            return $this->stateFromSettings(offlineMode: true);
        }

        return [
            'status'       => $status,
            'plan'         => $result['plan']       ?? '',
            'features'     => $features,
            'is_pro'       => $status === 'valid' && !empty($features),
            'expires_at'   => $result['expires_at'] ?? null,
            'domain'       => $result['domain']     ?? null,
            'checked_at'   => date('Y-m-d H:i:s'),
            'offline_mode' => false,
        ];
    }

    /**
     * Reads license state from app_settings rows (last persisted activation).
     * Used as offline fallback when the cache is unavailable or stale.
     */
    private function stateFromSettings(bool $offlineMode = false): array
    {
        try {
            $rows    = DB::table('app_settings')
                ->whereIn('key', ['license_status', 'license_plan', 'license_expires_at',
                                  'license_domain', 'license_validated_at'])
                ->pluck('value', 'key')
                ->toArray();

            $status   = $this->sanitizeStatus($rows['license_status'] ?? 'free');
            $plan     = $rows['license_plan'] ?? '';

            // In settings we don't store features separately — if plan is known,
            // grant all Pro features; the server will correct on next successful check.
            $features = ($status === 'valid' && $plan !== '')
                ? self::KNOWN_FEATURES
                : [];

            return [
                'status'       => $status,
                'plan'         => $plan,
                'features'     => $features,
                'is_pro'       => $status === 'valid' && !empty($features),
                'expires_at'   => $rows['license_expires_at']   ?? null,
                'domain'       => $rows['license_domain']        ?? null,
                'checked_at'   => $rows['license_validated_at']  ?? null,
                'offline_mode' => $offlineMode,
            ];
        } catch (\Throwable) {
            return $this->buildFreeState($offlineMode);
        }
    }

    // ──────────────────────────────────────────────────────────────────
    // Sanitisation helpers
    // ──────────────────────────────────────────────────────────────────

    private function sanitizeStatus(mixed $raw): string
    {
        $allowed = ['valid', 'invalid', 'expired', 'unreachable', 'free'];
        $s       = strtolower(trim((string) $raw));
        return in_array($s, $allowed, true) ? $s : 'invalid';
    }

    private function sanitizeFeatures(mixed $raw): array
    {
        if (!is_array($raw)) return [];
        return array_values(array_filter(
            array_map('strval', $raw),
            fn($f) => in_array($f, self::KNOWN_FEATURES, true)
        ));
    }
}