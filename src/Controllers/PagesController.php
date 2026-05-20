<?php
// src/Controllers/PagesController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\MetaGraphService;
use ModerationHub\Services\LicenseService;
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
        private readonly LicenseService   $license,
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
            ->whereNull('cp.disconnected_at')
            ->orderByDesc('cp.connected_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        return $this->json($response, $pages);
    }

    // ── GET /api/pages/login-config  ─────────────────────────────────
    /**
     * Returns the Facebook Login parameters the frontend SDK needs:
     * app_id, optional config_id (for Facebook Login for Business), graph version.
     */
    public function loginConfig(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        return $this->json($response, [
            'app_id'        => $_ENV['META_APP_ID'] ?? '',
            'config_id'     => $_ENV['META_FB_LOGIN_CONFIG_ID'] ?? null,
            'graph_version' => $_ENV['META_GRAPH_VERSION'] ?? 'v19.0',
        ]);
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

        // Mark which pages are already connected (disconnected pages can be re-added)
        $connectedIds = DB::table('connected_pages')
            ->whereNull('disconnected_at')
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

        // A row still connected (disconnected_at IS NULL) = already connected.
        // A disconnected row = will be reactivated, preserving its audit history.
        $existing = DB::table('connected_pages')
            ->where('page_id', $body['page_id'])
            ->first();

        if ($existing && $existing->disconnected_at === null) {
            return $this->json($response, ['error' => 'Page already connected'], 409);
        }

        // Free plan: max 1 connected page (disconnected pages don't count).
        if (!$this->license->hasFeature('multi_page')
            && DB::table('connected_pages')->whereNull('disconnected_at')->count() >= 1) {
            return $this->json($response, [
                'error'   => 'Il piano gratuito consente una sola pagina. Passa a Pro per collegarne altre.',
                'feature' => 'multi_page',
            ], 403);
        }

        // Subscribe webhook
        $webhookOk = false;
        try {
            $webhookOk = $this->meta->subscribePageWebhook($body['page_id'], $body['page_access_token']);
        } catch (\Throwable $e) {
            // Non-fatal: page gets connected, admin can retry webhook manually
        }

        if ($existing) {
            // Reactivate a previously disconnected page (its history is preserved).
            DB::table('connected_pages')->where('id', $existing->id)->update([
                'page_name'         => $body['page_name'],
                'page_access_token' => $body['page_access_token'],
                'admin_user_id'     => $auth->sub,
                'page_owner_fb_id'  => $body['owner_fb_id'] ?? null,
                'is_active'         => 1,
                'webhook_verified'  => $webhookOk ? 1 : 0,
                'disconnected_at'   => null,
                'updated_at'        => date('Y-m-d H:i:s'),
            ]);
            $id = $existing->id;
        } else {
            $id = DB::table('connected_pages')->insertGetId([
                'page_id'            => $body['page_id'],
                'page_name'          => $body['page_name'],
                'page_access_token'  => $body['page_access_token'],
                'admin_user_id'      => $auth->sub,
                'page_owner_fb_id'   => $body['owner_fb_id'] ?? null, // FB user ID of who connected the page
                'is_active'          => 1,
                'webhook_verified'   => $webhookOk ? 1 : 0,
                'connected_at'       => date('Y-m-d H:i:s'),
                'updated_at'         => date('Y-m-d H:i:s'),
            ]);
        }

        return $this->json($response, [
            'id'              => $id,
            'webhook_active'  => $webhookOk,
            'message'         => $webhookOk
                ? 'Page connected and webhook active'
                : 'Page connected but webhook subscription failed — retry from settings',
        ], 201);
    }

    // ── POST /api/pages/connect-batch  ───────────────────────────────
    /**
     * Connect multiple pages in a single call.
     * Body: { pages: [{ page_id, page_name, page_access_token, owner_fb_id? }, ...] }
     */
    public function connectBatch(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        $body = (array) $request->getParsedBody();
        $pages = $body['pages'] ?? [];

        if (!is_array($pages) || empty($pages)) {
            return $this->json($response, ['error' => 'pages array is required'], 422);
        }

        // Existing rows keyed by Meta page_id. Disconnected rows are reactivated
        // (preserving their audit history) rather than inserted again.
        $existingRows = DB::table('connected_pages')->get()->keyBy('page_id');
        $activeIds = $existingRows->filter(fn($r) => $r->disconnected_at === null)
                                  ->keys()->all();
        $now = date('Y-m-d H:i:s');

        // Free plan: max 1 connected page total. Pro (multi_page) unlocks unlimited.
        $canMulti = $this->license->hasFeature('multi_page');

        $results  = [];
        $counts   = ['connected' => 0, 'skipped' => 0, 'failed' => 0];

        foreach ($pages as $p) {
            $pageId    = $p['page_id']           ?? null;
            $pageName  = $p['page_name']         ?? null;
            $pageToken = $p['page_access_token'] ?? null;

            if (!$pageId || !$pageName || !$pageToken) {
                $results[] = ['page_id' => $pageId, 'status' => 'failed', 'error' => 'Missing required fields'];
                $counts['failed']++;
                continue;
            }

            if (in_array($pageId, $activeIds, true)) {
                $results[] = ['page_id' => $pageId, 'page_name' => $pageName, 'status' => 'skipped', 'reason' => 'already_connected'];
                $counts['skipped']++;
                continue;
            }

            // Enforce the free-plan single-page limit (disconnected pages don't count).
            if (!$canMulti && count($activeIds) >= 1) {
                $results[] = ['page_id' => $pageId, 'page_name' => $pageName, 'status' => 'failed', 'reason' => 'pro_required'];
                $counts['failed']++;
                continue;
            }

            $webhookOk = false;
            try {
                $webhookOk = $this->meta->subscribePageWebhook($pageId, $pageToken);
            } catch (\Throwable) {
                // Non-fatal: page gets connected, admin can retry webhook from UI
            }

            $existing = $existingRows->get($pageId);
            if ($existing) {
                // Reactivate a previously disconnected page.
                DB::table('connected_pages')->where('id', $existing->id)->update([
                    'page_name'         => $pageName,
                    'page_access_token' => $pageToken,
                    'admin_user_id'     => $auth->sub,
                    'page_owner_fb_id'  => $p['owner_fb_id'] ?? null,
                    'is_active'         => 1,
                    'webhook_verified'  => $webhookOk ? 1 : 0,
                    'disconnected_at'   => null,
                    'updated_at'        => $now,
                ]);
            } else {
                DB::table('connected_pages')->insert([
                    'page_id'           => $pageId,
                    'page_name'         => $pageName,
                    'page_access_token' => $pageToken,
                    'admin_user_id'     => $auth->sub,
                    'page_owner_fb_id'  => $p['owner_fb_id'] ?? null,
                    'is_active'         => 1,
                    'webhook_verified'  => $webhookOk ? 1 : 0,
                    'connected_at'      => $now,
                    'updated_at'        => $now,
                ]);
            }

            $activeIds[] = $pageId;
            $results[] = [
                'page_id'        => $pageId,
                'page_name'      => $pageName,
                'status'         => 'connected',
                'webhook_active' => $webhookOk,
            ];
            $counts['connected']++;
        }

        return $this->json($response, [
            'connected' => $counts['connected'],
            'skipped'   => $counts['skipped'],
            'failed'    => $counts['failed'],
            'results'   => $results,
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

        // Disconnect (soft): hide from the list and stop moderation (is_active=0),
        // but KEEP all data — moderation queue, logs, bans — for audit.
        // The free-plan slot is freed (disconnected pages don't count) and the same
        // page can be re-added later, which reactivates this row preserving history.
        // Use the "Pausa" toggle instead to disable moderation while keeping it listed.
        DB::table('connected_pages')->where('id', $page->id)->update([
            'is_active'       => 0,
            'disconnected_at' => date('Y-m-d H:i:s'),
            'updated_at'      => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['message' => "Pagina '{$page->page_name}' disconnessa"]);
    }

    // ── GET /api/pages/{id}/settings  ───────────────────────────────
    /**
     * Returns per-page AI threshold settings (PRO feature).
     * Returns global thresholds as fallback when page row is absent.
     */
    public function getPageSettings(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        if (!$this->license->hasFeature('per_page_thresholds')) {
            return $this->json($response, ['error' => 'Pro license required', 'feature' => 'per_page_thresholds'], 403);
        }

        $page = DB::table('connected_pages')->find((int) $args['id']);
        if (!$page) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        // Global fallbacks
        $globalHaiku  = (float) (DB::table('app_settings')->where('key', 'haiku_confidence_threshold')->value('value')  ?? 0.80);
        $globalSonnet = (float) (DB::table('app_settings')->where('key', 'sonnet_confidence_threshold')->value('value') ?? 0.70);

        $ps = DB::table('page_settings')->where('page_id', $page->id)->first();

        return $this->json($response, [
            'page_id'                     => $page->id,
            'page_name'                   => $page->page_name,
            'haiku_confidence_threshold'  => $ps ? (float) $ps->haiku_confidence_threshold  : null,
            'sonnet_confidence_threshold' => $ps ? (float) $ps->sonnet_confidence_threshold : null,
            'fact_check_enabled'          => $ps ? (bool)  $ps->fact_check_enabled          : true,
            // Whether the fact-check Pro feature is active (controls UI visibility)
            'fact_check_available'        => $this->license->hasFeature('fact_check'),
            // Effective values (null = using global)
            'effective_haiku'             => $ps?->haiku_confidence_threshold  !== null ? (float) $ps->haiku_confidence_threshold  : $globalHaiku,
            'effective_sonnet'            => $ps?->sonnet_confidence_threshold !== null ? (float) $ps->sonnet_confidence_threshold : $globalSonnet,
            'global_haiku'                => $globalHaiku,
            'global_sonnet'               => $globalSonnet,
        ]);
    }

    // ── PUT /api/pages/{id}/settings  ───────────────────────────────
    /**
     * Update per-page AI threshold settings (PRO feature).
     * Send null for a threshold to revert to global setting.
     * Body: { haiku_confidence_threshold, sonnet_confidence_threshold, fact_check_enabled }
     */
    public function updatePageSettings(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        if (!$this->license->hasFeature('per_page_thresholds')) {
            return $this->json($response, ['error' => 'Pro license required', 'feature' => 'per_page_thresholds'], 403);
        }

        $page = DB::table('connected_pages')->find((int) $args['id']);
        if (!$page) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $body = (array) $request->getParsedBody();

        // null = revert to global; value = page-specific override
        $haiku  = array_key_exists('haiku_confidence_threshold', $body)
            ? (($body['haiku_confidence_threshold'] === null || $body['haiku_confidence_threshold'] === '')
                ? null
                : max(0.01, min(1.0, (float) $body['haiku_confidence_threshold'])))
            : false; // false = not sent, don't touch

        $sonnet = array_key_exists('sonnet_confidence_threshold', $body)
            ? (($body['sonnet_confidence_threshold'] === null || $body['sonnet_confidence_threshold'] === '')
                ? null
                : max(0.01, min(1.0, (float) $body['sonnet_confidence_threshold'])))
            : false;

        // null = not sent (leave untouched); true/false = explicit value to save.
        $factCheck = array_key_exists('fact_check_enabled', $body)
            ? (bool) $body['fact_check_enabled']
            : null;

        // Validate that haiku > sonnet when both are set
        $haikuVal  = $haiku  !== false ? $haiku  : null;
        $sonnetVal = $sonnet !== false ? $sonnet : null;
        if ($haikuVal !== null && $sonnetVal !== null && $sonnetVal >= $haikuVal) {
            return $this->json($response, ['error' => 'Sonnet threshold must be lower than Haiku threshold'], 422);
        }

        $existing = DB::table('page_settings')->where('page_id', $page->id)->first();
        $now      = date('Y-m-d H:i:s');

        $data = ['updated_by' => $auth->sub, 'updated_at' => $now];
        if ($haiku  !== false) $data['haiku_confidence_threshold']  = $haikuVal;
        if ($sonnet !== false) $data['sonnet_confidence_threshold'] = $sonnetVal;
        if ($factCheck !== null)  $data['fact_check_enabled']       = $factCheck ? 1 : 0;

        if ($existing) {
            DB::table('page_settings')->where('page_id', $page->id)->update($data);
        } else {
            DB::table('page_settings')->insert(array_merge(['page_id' => $page->id], $data));
        }

        return $this->json($response, ['saved' => true]);
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