<?php
// src/Controllers/WebhookDebugController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use ModerationHub\Services\LicenseService;
use ModerationHub\Services\WebhookDebug;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Debug del webhook Meta (solo admin, licenza advanced_audit): attiva/disattiva l'acquisizione estesa e
 * mostra gli ultimi eventi ricevuti con payload grezzo e diagnostica.
 */
class WebhookDebugController
{
    public function __construct(private readonly LicenseService $license) {}

    // ── GET /api/webhook-debug ──────────────────────────────────────
    public function status(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $limit = max(1, min(100, (int) ($request->getQueryParams()['limit'] ?? 30)));
        $rows  = DB::table('webhook_events')
            ->orderByDesc('id')
            ->limit($limit)
            ->get(['id', 'page_id', 'event_type', 'payload', 'processed', 'error', 'debug', 'received_at'])
            ->map(function ($r) {
                $r = (array) $r;
                $r['debug'] = $r['debug'] !== null ? json_decode((string) $r['debug'], true) : null;
                return $r;
            })
            ->all();

        $until = WebhookDebug::until();
        return $this->json($response, [
            'active' => $until > time(),
            'until'  => $until > time() ? date('c', $until) : null,
            'events' => $rows,
        ]);
    }

    // ── POST /api/webhook-debug  { enabled: bool } ──────────────────
    public function toggle(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $auth    = $request->getAttribute('auth_user');
        $userId  = isset($auth->sub) ? (int) $auth->sub : (isset($auth->id) ? (int) $auth->id : null);
        $body    = (array) $request->getParsedBody();
        $enabled = !empty($body['enabled']);

        if ($enabled) {
            $until = WebhookDebug::enable($userId);
            return $this->json($response, ['active' => true, 'until' => date('c', $until)]);
        }
        WebhookDebug::disable($userId);
        return $this->json($response, ['active' => false, 'until' => null]);
    }

    // ── DELETE /api/webhook-debug  (svuota il log degli eventi) ─────
    public function clear(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $deleted = DB::table('webhook_events')->delete();
        return $this->json($response, ['deleted' => $deleted]);
    }

    private function requireAdmin(ServerRequestInterface $request, Response $response): ?ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }
        // Stessa licenza del registro audit (advanced_audit, o Advanced)
        if (!$this->license->canViewAudit()) {
            return $this->json($response, ['error' => 'Pro license required', 'feature' => 'advanced_audit'], 403);
        }
        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
