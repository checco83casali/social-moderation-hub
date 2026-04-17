<?php
// src/Controllers/ModerationController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\ModerationService;
use ModerationHub\Services\BanService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * REST endpoints for the moderation dashboard:
 *   - Human review queue
 *   - Apply human decisions
 *   - Ban management
 *   - Stats
 */
class ModerationController
{
    public function __construct(
        private readonly ModerationService $moderation,
        private readonly BanService        $ban,
    ) {}

    // ── GET /api/queue  ─────────────────────────────────────────────
    /** Returns all comments awaiting human review, newest first. */
    public function queue(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 25), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));

        $total = DB::table('comments')->where('status', 'escalated_human')->count();

        $items = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->where('c.status', 'escalated_human')
            ->select([
                'c.id',
                'c.content',
                'c.received_at',
                'c.platform_comment_id',
                'su.id as social_user_id',
                'su.display_name',
                'su.violation_count',
                'su.ban_status',
                'cp.page_name',
                'cp.page_id as facebook_page_id',
                'ml.stage as ai_stage',
                'ml.ai_decision',
                'ml.ai_confidence',
                'ml.ai_reason',
                'ml.ai_categories',
                'ml.ai_severity',
            ])
            ->orderByDesc('c.received_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
                return $arr;
            })
            ->toArray();

        return $this->json($response, [
            'total'    => $total,
            'page'     => $page,
            'per_page' => $limit,
            'items'    => $items,
        ]);
    }

    // ── POST /api/comments/{id}/decide  ─────────────────────────────
    /** Apply a human moderation decision (allow / remove). */
    public function decide(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $auth = $request->getAttribute('auth_user');

        $decision = $body['decision'] ?? '';
        if (!in_array($decision, ['allow', 'remove'], true)) {
            return $this->json($response, ['error' => 'Invalid decision. Use allow or remove.'], 422);
        }

        $result = $this->moderation->applyHumanDecision(
            commentId:   (int) $args['id'],
            decision:    $decision,
            adminUserId: $auth->sub,
            note:        $body['note'] ?? '',
        );

        return $this->json($response, $result);
    }

    // ── GET /api/users/{id}  ─────────────────────────────────────────
    /** Social user detail with ban history. */
    public function userDetail(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $user = DB::table('social_users')->find((int) $args['id']);
        if (!$user) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $banHistory = DB::table('ban_records as b')
            ->leftJoin('admin_users as a', 'a.id', '=', 'b.admin_user_id')
            ->where('b.social_user_id', $user->id)
            ->select(['b.*', 'a.name as admin_name'])
            ->orderByDesc('b.created_at')
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        $recentComments = DB::table('comments')
            ->where('social_user_id', $user->id)
            ->orderByDesc('received_at')
            ->limit(10)
            ->get()
            ->map(fn($r) => (array) $r)
            ->toArray();

        return $this->json($response, [
            'user'            => (array) $user,
            'ban_status'      => $this->ban->getUserBanStatus($user->id),
            'ban_history'     => $banHistory,
            'recent_comments' => $recentComments,
        ]);
    }

    // ── POST /api/users/{id}/ban  ─────────────────────────────────────
    /** Manually ban a user (admin only). */
    public function banUser(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $auth = $request->getAttribute('auth_user');

        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $result = $this->ban->applyUserBan(
            socialUserId: (int) $args['id'],
            pageId:       (int) ($body['page_id'] ?? 0) ?: null,
            logId:        0,
            commentId:    0,
            decidedBy:    'human',
            adminUserId:  $auth->sub,
            reason:       $body['reason'] ?? 'Manual ban by admin',
            categories:   $body['categories'] ?? [],
        );

        return $this->json($response, ['action' => $result]);
    }

    // ── DELETE /api/users/{id}/ban  ──────────────────────────────────
    /** Lift a user ban (admin only). */
    public function liftBan(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth   = $request->getAttribute('auth_user');
        $body   = (array) $request->getParsedBody();

        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $lifted = $this->ban->liftBan(
            socialUserId: (int) $args['id'],
            adminUserId:  $auth->sub,
            reason:       $body['reason'] ?? '',
        );

        return $this->json($response, ['lifted' => $lifted]);
    }

    // ── GET /api/stats  ─────────────────────────────────────────────
    /** Dashboard summary stats. */
    public function stats(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $since = date('Y-m-d H:i:s', strtotime('-30 days'));

        $data = [
            'queue_pending'     => DB::table('comments')->where('status', 'escalated_human')->count(),
            'total_comments_30d' => DB::table('comments')->where('received_at', '>=', $since)->count(),
            'removed_30d'       => DB::table('comments')->where('status', 'removed')->where('processed_at', '>=', $since)->count(),
            'approved_30d'      => DB::table('comments')->where('status', 'approved')->where('processed_at', '>=', $since)->count(),
            'active_bans'       => DB::table('ban_records')
                ->where('ban_scope', 'user')
                ->where('is_active', 1)
                ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s')); })
                ->count(),
            'by_stage'          => DB::table('moderation_log')
                ->where('created_at', '>=', $since)
                ->selectRaw('stage, COUNT(*) as count')
                ->groupBy('stage')
                ->pluck('count', 'stage'),
            'by_ai_decision'    => DB::table('moderation_log')
                ->where('created_at', '>=', $since)
                ->whereIn('stage', ['haiku', 'sonnet'])
                ->selectRaw('ai_decision, COUNT(*) as count')
                ->groupBy('ai_decision')
                ->pluck('count', 'ai_decision'),
        ];

        return $this->json($response, $data);
    }

    // ── GET /api/learning-data  ─────────────────────────────────────
    /** Export human ban decisions for policy review / AI training. Admin only. */
    public function learningData(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $data = (new BanService)->getHumanBanLearningData();
        return $this->json($response, ['data' => $data, 'count' => count($data)]);
    }

    // ──────────────────────────────────────────────────────────────────

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
