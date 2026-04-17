<?php
// src/Controllers/PagesController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\MetaGraphService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Handles connecting and managing Facebook Pages.
 *
 * Flow:
 *   1. Admin provides a short-lived user token (from FB Login in the frontend)
 *   2. We exchange it for a long-lived token
 *   3. We list their managed pages
 *   4. Admin selects a page → we store the page access token + subscribe webhook
 */
class PagesController
{
    public function __construct(
        private readonly MetaGraphService $meta,
    ) {}

    // ── GET /api/pages  ──────────────────────────────────────────────
    /** List all connected pages. */
    public function index(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $pages = DB::table('connected_pages as cp')
            ->join('admin_users as a', 'a.id', '=', 'cp.admin_user_id')
            ->select([
                'cp.id', 'cp.page_id', 'cp.page_name',
                'cp.is_active', 'cp.webhook_verified', 'cp.connected_at',
                'a.name as connected_by',
            ])
            ->orderByDesc('cp.connected_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        return $this->json($response, $pages);
    }

    // ── POST /api/pages/available  ───────────────────────────────────
    /**
     * Given a short-lived user token from the frontend,
     * returns the list of pages the user manages on Facebook.
     */
    public function available(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body       = (array) $request->getParsedBody();
        $shortToken = $body['user_token'] ?? '';

        if (empty($shortToken)) {
            return $this->json($response, ['error' => 'user_token is required'], 422);
        }

        try {
            $longToken = $this->meta->getLongLivedToken($shortToken);
            $pages     = $this->meta->getManagedPages($longToken);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Meta API error: ' . $e->getMessage()], 502);
        }

        // Mark which pages are already connected
        $connectedIds = DB::table('connected_pages')
            ->pluck('page_id')
            ->toArray();

        $pages = array_map(function ($page) use ($connectedIds) {
            $page['already_connected'] = in_array($page['id'], $connectedIds, true);
            // Never expose raw token to frontend
            unset($page['access_token']);
            return $page;
        }, $pages);

        return $this->json($response, [
            'long_lived_token' => $longToken, // frontend stores this temporarily to call /connect
            'pages'            => $pages,
        ]);
    }

    // ── POST /api/pages/connect  ─────────────────────────────────────
    /**
     * Connect a specific page. Stores the page token and subscribes webhook.
     * Body: { page_id, page_name, page_access_token }
     */
    public function connect(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        $body = (array) $request->getParsedBody();

        $required = ['page_id', 'page_name', 'page_access_token'];
        foreach ($required as $field) {
            if (empty($body[$field])) {
                return $this->json($response, ['error' => "Field '{$field}' is required"], 422);
            }
        }

        // Check not already connected
        $existing = DB::table('connected_pages')
            ->where('page_id', $body['page_id'])
            ->first();

        if ($existing) {
            return $this->json($response, ['error' => 'Page already connected'], 409);
        }

        // Subscribe webhook
        $webhookOk = false;
        try {
            $webhookOk = $this->meta->subscribePageWebhook($body['page_id'], $body['page_access_token']);
        } catch (\Throwable $e) {
            // Non-fatal: page gets connected, admin can retry webhook manually
        }

        $id = DB::table('connected_pages')->insertGetId([
            'page_id'           => $body['page_id'],
            'page_name'         => $body['page_name'],
            'page_access_token' => $body['page_access_token'],
            'admin_user_id'     => $auth->sub,
            'is_active'         => 1,
            'webhook_verified'  => $webhookOk ? 1 : 0,
            'connected_at'      => date('Y-m-d H:i:s'),
            'updated_at'        => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, [
            'id'              => $id,
            'webhook_active'  => $webhookOk,
            'message'         => $webhookOk
                ? 'Page connected and webhook active'
                : 'Page connected but webhook subscription failed — retry from settings',
        ], 201);
    }

    // ── POST /api/pages/{id}/webhook/retry  ──────────────────────────
    /** Re-attempt webhook subscription for a page. */
    public function retryWebhook(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $page = DB::table('connected_pages')->find((int) $args['id']);
        if (!$page) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        try {
            $ok = $this->meta->subscribePageWebhook($page->page_id, $page->page_access_token);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Meta API error: ' . $e->getMessage()], 502);
        }

        DB::table('connected_pages')->where('id', $page->id)->update([
            'webhook_verified' => $ok ? 1 : 0,
            'updated_at'       => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['webhook_active' => $ok]);
    }

    // ── PUT /api/pages/{id}/toggle  ──────────────────────────────────
    /** Enable or disable moderation for a page without disconnecting it. */
    public function toggle(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $page = DB::table('connected_pages')->find((int) $args['id']);
        if (!$page) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $newState = $page->is_active ? 0 : 1;
        DB::table('connected_pages')->where('id', $page->id)->update([
            'is_active'  => $newState,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, [
            'is_active' => (bool) $newState,
            'message'   => $newState ? 'Moderation enabled' : 'Moderation paused',
        ]);
    }

    // ── DELETE /api/pages/{id}  ──────────────────────────────────────
    /** Disconnect a page (admin only). Keeps historical comment data. */
    public function disconnect(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $page = DB::table('connected_pages')->find((int) $args['id']);
        if (!$page) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        // Soft-delete: mark inactive instead of deleting (preserves FK references)
        DB::table('connected_pages')->where('id', $page->id)->update([
            'is_active'  => 0,
            'updated_at' => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['message' => "Page '{$page->page_name}' disconnected"]);
    }

    // ─────────────────────────────────────────────────────────────────

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
