<?php
// src/Controllers/LicenseController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\LicenseService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * REST endpoints for license management.
 *
 * GET  /api/license          — status (all authenticated users)
 * PUT  /api/license          — activate a key (admin only)
 * DELETE /api/license        — deactivate (admin only)
 * POST /api/license/refresh  — force remote re-check (admin only)
 */
class LicenseController
{
    public function __construct(
        private readonly LicenseService $license,
    ) {}

    // ── GET /api/license ────────────────────────────────────────────
    /**
     * Returns current license status and feature list.
     * Safe for all authenticated users: key is never exposed.
     */
    public function status(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth    = $request->getAttribute('auth_user');
        $isAdmin = ($auth->role ?? '') === 'admin';

        $status = $this->license->getStatus();

        // Non-admins see only the feature flags, not key metadata
        if (!$isAdmin) {
            return $this->json($response, [
                'is_pro'   => $status['is_pro'],
                'features' => $status['features'],
            ]);
        }

        // Admins also see whether a key is stored (masked) and domain
        $keyRaw = DB::table('app_settings')->where('key', 'license_key')->value('value') ?? '';
        $status['key_configured'] = $keyRaw !== '';
        $status['key_masked']     = $this->maskKey($keyRaw);

        return $this->json($response, $status);
    }

    // ── PUT /api/license ────────────────────────────────────────────
    /**
     * Activate a new license key.
     * Body: { "key": "XXXX-XXXX-XXXX-XXXX", "domain": "example.com" }
     */
    public function activate(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $body   = (array) $request->getParsedBody();
        $key    = trim((string) ($body['key']    ?? ''));
        $domain = trim((string) ($body['domain'] ?? ''));

        if ($key === '') {
            return $this->json($response, ['error' => 'License key is required'], 422);
        }

        // Infer domain from app_url if not provided
        if ($domain === '') {
            $appUrl = DB::table('app_settings')->where('key', 'app_url')->value('value') ?? '';
            $domain = parse_url($appUrl, PHP_URL_HOST) ?? '';
        }

        $result = $this->license->activate($key, $domain, $auth->sub);

        $httpStatus = $result['ok'] ? 200 : 422;
        return $this->json($response, [
            'ok'       => $result['ok'],
            'status'   => $result['status'],
            'plan'     => $result['plan'],
            'features' => $result['features'],
            'error'    => $result['error'] ?? null,
        ], $httpStatus);
    }

    // ── DELETE /api/license ─────────────────────────────────────────
    /** Remove license key and reset to free. */
    public function deactivate(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $this->license->deactivate($auth->sub);

        return $this->json($response, ['ok' => true, 'status' => 'free']);
    }

    // ── POST /api/license/refresh ───────────────────────────────────
    /** Force a remote re-validation now, ignoring the cache TTL. */
    public function refresh(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $result = $this->license->forceRefresh($auth->sub);

        return $this->json($response, [
            'ok'       => $result['ok'],
            'status'   => $result['status'],
            'plan'     => $result['plan'],
            'features' => $result['features'],
            'error'    => $result['error'] ?? null,
        ]);
    }

    // ──────────────────────────────────────────────────────────────────
    // Helpers
    // ──────────────────────────────────────────────────────────────────

    private function maskKey(string $key): string
    {
        if (strlen($key) <= 8) return str_repeat('*', strlen($key));
        return substr($key, 0, 4) . str_repeat('*', max(0, strlen($key) - 8)) . substr($key, -4);
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}