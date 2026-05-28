<?php
// src/Controllers/ModerationController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\ModerationService;
use ModerationHub\Services\BanService;
use ModerationHub\Services\MetaGraphService;
use ModerationHub\Services\LicenseService;
use ModerationHub\Services\RetentionService;
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
        private readonly MetaGraphService  $meta,
        private readonly LicenseService    $license,
    ) {}

    // ── GET /api/queue  ─────────────────────────────────────────────
    /** Returns comments awaiting human review (uncertain AI decisions only). */
    public function queue(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 25), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));

        // Only truly uncertain comments — reportable go to /queue/reportable
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
                'c.id', 'c.content', 'c.received_at', 'c.platform_comment_id', 'c.status',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count', 'su.ban_status',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.stage as ai_stage', 'ml.ai_decision', 'ml.ai_confidence',
                'ml.ai_reason', 'ml.ai_public_reason', 'ml.ai_categories', 'ml.ai_severity',
                'ml.ai_fact_check_draft', 'ml.ai_fact_check_sources',
                'ml.ai_fact_check_confidence', 'ml.ai_fact_check_suggested',
                'ml.ai_whataboutism_suggested', 'ml.ai_whataboutism_draft',
                'ml.ai_whataboutism_confidence',
                'ml.ai_editorial_category',
                'c.platform_post_id',
            ])
            ->orderByDesc('c.received_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories']        = json_decode($arr['ai_categories'] ?? '[]', true);
                $arr['ai_fact_check_sources'] = json_decode($arr['ai_fact_check_sources'] ?? '[]', true);
                $arr['is_reportable']         = false;
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

    // ── GET /api/queue/reportable  ──────────────────────────────────
    /** Dangerous/potentially illegal comments hidden by AI, awaiting human decision on reporting. */
    public function reportableQueue(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 25), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));

        $total = DB::table('comments')->where('status', 'escalated_reportable')->count();

        $items = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->where('c.status', 'escalated_reportable')
            ->select([
                'c.id', 'c.content', 'c.received_at', 'c.platform_comment_id', 'c.status',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count', 'su.ban_status',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.stage as ai_stage', 'ml.ai_decision', 'ml.ai_confidence',
                'ml.ai_reason', 'ml.ai_public_reason', 'ml.ai_categories', 'ml.ai_severity',
                'c.platform_post_id',
            ])
            ->orderByDesc('c.received_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
                $arr['is_reportable'] = true;
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

    // ── GET /api/queue/reportable/archive  ───────────────────────────
    /** Historical reports already escalated to the legal team (reported_legal). */
    public function reportableArchive(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 50), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));

        $total = DB::table('comments')->where('status', 'reported_legal')->count();

        $items = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->leftJoin('admin_users as au', 'au.id', '=', 'ml.human_user_id')
            ->where('c.status', 'reported_legal')
            ->select([
                'c.id', 'c.content', 'c.received_at', 'c.processed_at', 'c.platform_comment_id',
                'su.display_name', 'su.violation_count',
                'cp.page_name',
                'ml.ai_categories', 'ml.ai_severity', 'ml.human_note', 'ml.human_decided_at',
                DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS reported_by"),
            ])
            ->orderByDesc('c.processed_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
                return $arr;
            })
            ->toArray();

        return $this->json($response, compact('total', 'page', 'items') + ['per_page' => $limit]);
    }

    // ── POST /api/comments/{id}/report-legal  ────────────────────────
    /**
     * Start the legal-reporting procedure for an illegal comment.
     * The comment stays hidden (never deleted); status becomes reported_legal
     * and it moves to the reports archive. A PDF dossier is downloadable from
     * GET /api/comments/{id}/legal-dossier. The user can still appeal (GDPR).
     */
    public function reportLegal(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (!in_array($auth->role ?? '', ['admin', 'supervisor'], true)) {
            return $this->json($response, ['error' => 'Admin or supervisor required'], 403);
        }

        $commentId = (int) $args['id'];
        $comment   = DB::table('comments')->find($commentId);
        if (!$comment) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        $note = (string) (((array) $request->getParsedBody())['note'] ?? '');
        $now  = date('Y-m-d H:i:s');

        DB::table('comments')->where('id', $commentId)->update([
            'status'       => 'reported_legal',
            'processed_at' => $now,
        ]);

        $policyId = DB::table('policies')->where('is_active', 1)->value('id') ?? 1;
        DB::table('moderation_log')->insert([
            'comment_id'       => $commentId,
            'stage'            => 'human',
            'policy_id'        => $policyId,
            'human_user_id'    => $auth->sub,
            'human_decision'   => 'report_legal',
            'human_note'       => $note ?: 'Iter di segnalazione alle autorità avviato',
            'human_decided_at' => $now,
            'final_action'     => 'reported_legal',
            'created_at'       => $now,
        ]);

        return $this->json($response, [
            'ok'          => true,
            'comment_id'  => $commentId,
            'dossier_url' => '/api/comments/' . $commentId . '/legal-dossier',
        ]);
    }

    // ── GET /api/comments/{id}/legal-dossier  ────────────────────────
    /** Generate (on demand, always re-downloadable) the PDF dossier for the legal team. */
    public function legalDossier(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (!in_array($auth->role ?? '', ['admin', 'supervisor'], true)) {
            return $this->json($response, ['error' => 'Admin or supervisor required'], 403);
        }

        $commentId = (int) $args['id'];
        $row = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw("ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id AND stage IN ('haiku','sonnet'))");
            })
            ->where('c.id', $commentId)
            ->select([
                'c.id', 'c.content', 'c.received_at', 'c.processed_at',
                'c.platform_comment_id', 'c.platform_post_id',
                'su.display_name', 'su.platform_user_id', 'su.violation_count', 'su.ban_status',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.stage', 'ml.ai_model', 'ml.ai_decision', 'ml.ai_confidence',
                'ml.ai_reason', 'ml.ai_public_reason', 'ml.ai_categories', 'ml.ai_severity',
            ])
            ->first();

        if (!$row) {
            return $this->json($response, ['error' => 'Not found'], 404);
        }

        // Who started the legal procedure, and when
        $report = DB::table('moderation_log as ml')
            ->leftJoin('admin_users as au', 'au.id', '=', 'ml.human_user_id')
            ->where('ml.comment_id', $commentId)
            ->where('ml.final_action', 'reported_legal')
            ->orderByDesc('ml.id')
            ->select(['ml.human_decided_at', 'ml.human_note', DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS reported_by")])
            ->first();

        $html = $this->buildLegalDossierHtml((array) $row, $report ? (array) $report : []);

        try {
            // dompdf deve poter SCRIVERE la cache dei font: di default punta dentro
            // vendor/, spesso non scrivibile dall'utente del web server (errore in
            // render()). La spostiamo in una dir temporanea scrivibile a runtime.
            $cacheDir = sys_get_temp_dir() . '/smh-dompdf';
            if (!is_dir($cacheDir)) {
                @mkdir($cacheDir, 0775, true);
            }

            $options = new \Dompdf\Options();
            $options->set('isRemoteEnabled', false);
            $options->set('defaultFont', 'DejaVu Sans');
            $options->set('tempDir', $cacheDir);
            $options->set('fontDir', $cacheDir);
            $options->set('fontCache', $cacheDir);

            $dompdf = new \Dompdf\Dompdf($options);
            $dompdf->loadHtml($html, 'UTF-8');
            $dompdf->setPaper('A4', 'portrait');
            $dompdf->render();

            $response->getBody()->write($dompdf->output());
            return $response
                ->withHeader('Content-Type', 'application/pdf')
                ->withHeader('Content-Disposition', 'attachment; filename="dossier-segnalazione-' . $commentId . '.pdf"');
        } catch (\Throwable $e) {
            error_log('[legalDossier] PDF generation failed for comment ' . $commentId . ': ' . $e->getMessage());
            return $this->json($response, [
                'error' => 'Generazione PDF fallita: ' . $e->getMessage(),
            ], 500);
        }
    }

    /** Build the HTML for the legal dossier PDF. */
    private function buildLegalDossierHtml(array $c, array $report): string
    {
        $e = fn($v) => htmlspecialchars((string) ($v ?? ''), ENT_QUOTES, 'UTF-8');
        $cats = is_array($c['ai_categories'] ?? null)
            ? $c['ai_categories']
            : (json_decode($c['ai_categories'] ?? '[]', true) ?: []);
        $catsStr   = $e(implode(', ', $cats));
        $generated = date('d/m/Y H:i');

        $row = fn($label, $val) =>
            '<tr><td style="font-weight:bold;width:38%;background:#f4f4f4;padding:6px 8px;border:1px solid #ccc">'
            . $e($label) . '</td><td style="padding:6px 8px;border:1px solid #ccc">' . $val . '</td></tr>';

        return '<!DOCTYPE html><html><head><meta charset="utf-8"><style>'
            . 'body{font-family:DejaVu Sans,sans-serif;font-size:11px;color:#222}'
            . 'h1{font-size:16px;margin:0 0 2px}h2{font-size:12px;margin:16px 0 6px;color:#444}'
            . 'table{border-collapse:collapse;width:100%}.muted{color:#777;font-size:9px}'
            . '.box{border:1px solid #ccc;background:#fafafa;padding:8px;white-space:pre-wrap}'
            . '</style></head><body>'
            . '<h1>Dossier di segnalazione — contenuto illecito</h1>'
            . '<div class="muted">Documento generato automaticamente il ' . $generated
            . ' · Riferimento commento #' . $e($c['id']) . '</div>'
            . '<h2>Procedura</h2><table>'
            . $row('Avviata da', $e($report['reported_by'] ?? '—'))
            . $row('Data avvio iter', $e($report['human_decided_at'] ?? $c['processed_at'] ?? '—'))
            . $row('Note moderatore', $e($report['human_note'] ?? '—'))
            . '</table>'
            . '<h2>Pagina</h2><table>'
            . $row('Nome pagina', $e($c['page_name']))
            . $row('ID pagina Facebook', $e($c['facebook_page_id']))
            . $row('ID post', $e($c['platform_post_id']))
            . '</table>'
            . '<h2>Autore del commento</h2><table>'
            . $row('Nome visualizzato', $e($c['display_name']))
            . $row('Identificativo pseudonimizzato', $e($c['platform_user_id']))
            . $row('Violazioni totali', $e($c['violation_count']))
            . $row('Stato ban', $e($c['ban_status']))
            . '</table>'
            . '<h2>Commento</h2>'
            . '<table>' . $row('ID commento Facebook', $e($c['platform_comment_id'])) . '</table>'
            . '<div class="box">' . $e($c['content']) . '</div>'
            . '<table style="margin-top:6px">'
            . $row('Ricevuto il', $e($c['received_at']))
            . $row('Nascosto/segnalato il', $e($c['processed_at']))
            . '</table>'
            . '<h2>Analisi AI</h2><table>'
            . $row('Modello / stadio', $e(($c['ai_model'] ?? '—') . ' (' . ($c['stage'] ?? '—') . ')'))
            . $row('Decisione', $e($c['ai_decision']))
            . $row('Confidenza', $e($c['ai_confidence']))
            . $row('Severità', $e($c['ai_severity']))
            . $row('Categorie', $catsStr)
            . $row('Motivazione interna', $e($c['ai_reason']))
            . $row('Motivazione pubblica', $e($c['ai_public_reason']))
            . '</table>'
            . '<p class="muted" style="margin-top:18px">Il commento è stato nascosto al pubblico e non eliminato. '
            . 'I dati identificativi diretti sono pseudonimizzati secondo la policy GDPR dell\'installazione.</p>'
            . '</body></html>';
    }

    // ── GET /api/appeals  ───────────────────────────────────────────
    /** Returns pending appeal requests, newest first. */
    public function appealQueue(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 25), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));

        $total = DB::table('appeal_records')->where('status', 'pending')->count();

        $items = DB::table('appeal_records as ar')
            ->join('comments as c', 'c.id', '=', 'ar.comment_id')
            ->join('social_users as su', 'su.id', '=', 'ar.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->where('ar.status', 'pending')
            ->select([
                'ar.id as appeal_id', 'ar.appeal_text', 'ar.submitted_at',
                'c.id as comment_id', 'c.content', 'c.status as comment_status',
                'c.platform_comment_id', 'c.platform_post_id',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.ai_reason', 'ml.ai_public_reason', 'ml.ai_categories', 'ml.ai_severity',
                'ml.removal_reply_text',
            ])
            ->orderBy('ar.submitted_at')
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

    // ── POST /api/appeals/{id}/decide  ──────────────────────────────
    /** Accept or reject an appeal. Accepting unhides the comment on Facebook. */
    public function decideAppeal(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $body     = (array) $request->getParsedBody();
        $auth     = $request->getAttribute('auth_user');
        $decision = $body['decision'] ?? ''; // accept | reject

        if (!in_array($decision, ['accept', 'reject'], true)) {
            return $this->json($response, ['error' => 'Invalid decision. Use accept or reject.'], 422);
        }

        $appeal = DB::table('appeal_records')->find((int) $args['id']);
        if (!$appeal) {
            return $this->json($response, ['error' => 'Appeal not found.'], 404);
        }
        if ($appeal->status !== 'pending') {
            return $this->json($response, ['error' => 'Appeal already reviewed.'], 409);
        }

        DB::table('appeal_records')->where('id', $appeal->id)->update([
            'status'        => $decision === 'accept' ? 'accepted' : 'rejected',
            'reviewed_by'   => $auth->sub,
            'reviewed_at'   => date('Y-m-d H:i:s'),
            'reviewer_note' => $body['note'] ?? '',
        ]);

        if ($decision === 'accept') {
            $result = $this->moderation->applyHumanDecision(
                commentId:   (int) $appeal->comment_id,
                decision:    'unhide',
                adminUserId: $auth->sub,
                note:        $body['note'] ?? 'Appeal accepted',
            );
            // applyHumanDecision sets status = 'approved' — already correct
            return $this->json($response, array_merge($result, ['appeal_decision' => 'accepted']));
        }

        // Reject: comment stays hidden — restore status from appeal_pending → hidden
        $comment = DB::table('comments')->find((int) $appeal->comment_id);
        $page    = $comment ? DB::table('connected_pages')->find($comment->page_id) : null;

        DB::table('comments')->where('id', $appeal->comment_id)->update([
            'status'       => 'hidden',
            'processed_at' => date('Y-m-d H:i:s'),
        ]);

        if ($comment && $page && $comment->platform_comment_id) {
            $reviewerNote = trim($body['note'] ?? '');
            $replyText    = "Il tuo ricorso è stato esaminato dai nostri moderatori. La decisione di nascondere il commento è stata confermata.";
            if ($reviewerNote) {
                $replyText .= "\n\nMotivazione: {$reviewerNote}";
            }
            $this->meta->replyToComment(
                $comment->platform_comment_id,
                $replyText,
                $page->page_access_token,
            );
        }

        DB::table('moderation_log')->insertGetId([
            'comment_id'        => $appeal->comment_id,
            'stage'             => 'human',
            'policy_id'         => DB::table('policies')->where('is_active', 1)->value('id') ?? 1,
            'human_user_id'     => $auth->sub,
            'human_decision'    => 'hide',
            'human_note'        => 'Appeal rejected: ' . ($body['note'] ?? ''),
            'human_decided_at'  => date('Y-m-d H:i:s'),
            'removal_reply_sent'=> isset($replyText) ? 1 : 0,
            'removal_reply_text'=> $replyText ?? null,
            'final_action'      => 'hidden',
            'created_at'        => date('Y-m-d H:i:s'),
        ]);

        return $this->json($response, ['action' => 'appeal_rejected', 'comment_id' => $appeal->comment_id]);
    }

    // ── GET /api/comments/hidden  ────────────────────────────────────
    /** All hidden comments (pending appeal or confirmed). */
    public function hiddenComments(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 25), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));
        $signal   = $params['signal']   ?? 'all';
        $category = $params['category'] ?? '';

        $query = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->leftJoin('appeal_records as ar', function ($join) {
                $join->on('ar.comment_id', '=', 'c.id')
                     ->whereRaw('ar.id = (SELECT MAX(id) FROM appeal_records WHERE comment_id = c.id)');
            })
            ->leftJoin('admin_users as au', 'au.id', '=', 'ml.human_user_id')
            ->whereIn('c.status', ['hidden', 'hidden_reportable']);

        if ($signal === 'fact_check') {
            $query->where('ml.ai_fact_check_suggested', 1);
        } elseif ($signal === 'whataboutism') {
            $query->where('ml.ai_whataboutism_suggested', 1);
        } elseif ($signal === 'any') {
            $query->where(function ($q) {
                $q->where('ml.ai_fact_check_suggested', 1)
                  ->orWhere('ml.ai_whataboutism_suggested', 1);
            });
        } elseif ($signal === 'none') {
            $query->where('ml.ai_fact_check_suggested', 0)
                  ->where('ml.ai_whataboutism_suggested', 0);
        }

        if ($category !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $category) === 1) {
            $query->whereRaw('JSON_CONTAINS(ml.ai_categories, ?)', [json_encode($category)]);
        }

        $total = (clone $query)->count();

        $items = $query
            ->select([
                'c.id', 'c.content', 'c.received_at', 'c.processed_at', 'c.status',
                'c.platform_comment_id', 'c.platform_post_id',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.ai_reason', 'ml.ai_public_reason', 'ml.ai_categories', 'ml.ai_severity',
                'ml.removal_reply_text', 'ml.human_decision',
                'ml.ai_fact_check_suggested', 'ml.ai_fact_check_confidence',
                'ml.ai_whataboutism_suggested', 'ml.ai_whataboutism_confidence',
                DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS decided_by_name"),
                'ar.id as appeal_id', 'ar.status as appeal_status', 'ar.submitted_at as appeal_submitted_at',
            ])
            ->orderByDesc('c.processed_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories']  = json_decode($arr['ai_categories'] ?? '[]', true);
                $arr['is_reportable']  = $arr['status'] === 'hidden_reportable';
                $arr['has_appeal']     = !is_null($arr['appeal_id']);
                // Chi ha nascosto: il nome c'è solo se human_user_id era valorizzato,
                // cioè quando un moderatore ha agito (hide o conferma reportable).
                // L'auto-hide dell'AI lascia human_user_id NULL → nessun nome.
                $arr['hidden_by_human'] = !is_null($arr['decided_by_name']);
                return $arr;
            })
            ->toArray();

        return $this->json($response, compact('total', 'page', 'items') + ['per_page' => $limit]);
    }

    // ── POST /api/comments/{id}/decide  ─────────────────────────────
    /** Apply a human moderation decision (allow / hide / unhide / keep_hidden). */
    public function decide(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $auth = $request->getAttribute('auth_user');

        $decision = $body['decision'] ?? '';
        if (!in_array($decision, ['allow', 'hide', 'unhide', 'keep_hidden'], true)) {
            return $this->json($response, ['error' => 'Invalid decision.'], 422);
        }

        if ($this->isDevMode()) {
            $status = match($decision) {
                'hide'   => 'dev_flagged',
                default  => 'dev_approved',
            };
            DB::table('comments')->where('id', (int) $args['id'])->update([
                'status'       => $status,
                'processed_at' => date('Y-m-d H:i:s'),
            ]);
            return $this->json($response, [
                'dev_mode' => true,
                'action'   => $status,
                'note'     => 'Dev mode active — no real action taken.',
            ]);
        }

        $result = $this->moderation->applyHumanDecision(
            commentId:   (int) $args['id'],
            decision:    $decision,
            adminUserId: $auth->sub,
            note:        $body['note'] ?? '',
            silent:      (bool) ($body['silent'] ?? false),
        );

        return $this->json($response, $result);
    }

    // ── POST /api/comments/{id}/reply  ──────────────────────────────
    /**
     * Post a public reply on Facebook without removing the comment.
     * Used for fact-check, educational replies, and assisted removal replies.
     */
    public function reply(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        if ($this->isDevMode()) {
            return $this->json($response, [
                'dev_mode' => true,
                'action'   => 'dev_reply_skipped',
                'note'     => 'Dev mode active — reply not sent.',
            ]);
        }

        $body = (array) $request->getParsedBody();
        $text = trim((string) ($body['text'] ?? ''));
        if (empty($text)) {
            return $this->json($response, ['error' => 'Reply text is required.'], 422);
        }

        $comment = DB::table('comments')->find((int) $args['id']);
        if (!$comment) return $this->json($response, ['error' => 'Comment not found.'], 404);

        $page = DB::table('connected_pages')->find($comment->page_id);
        if (!$page) return $this->json($response, ['error' => 'Page not found.'], 404);

        $res  = $this->meta->replyToCommentResult(
            $comment->platform_comment_id,
            $text,
            $page->page_access_token,
        );
        $sent = $res['ok'];

        $log = DB::table('moderation_log')
            ->where('comment_id', $comment->id)
            ->orderByDesc('id')->first();

        if ($log) {
            DB::table('moderation_log')->where('id', $log->id)->update([
                'removal_reply_sent' => $sent ? 1 : 0,
                'removal_reply_text' => $text,
            ]);
        }

        if (!$sent) {
            return $this->json($response, [
                'error' => 'Facebook ha rifiutato la risposta: ' . ($res['error'] ?? 'motivo sconosciuto'),
                'sent'  => false,
            ], 502);
        }

        return $this->json($response, ['sent' => true]);
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
    /** Manually ban a user (admin or moderator). */
    public function banUser(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $body = (array) $request->getParsedBody();
        $auth = $request->getAttribute('auth_user');

        if (!in_array($auth->role ?? '', ['admin', 'moderator'], true)) {
            return $this->json($response, ['error' => 'Moderator or admin required'], 403);
        }

        $result = $this->ban->applyUserBan(
            socialUserId: (int) $args['id'],
            pageId:       ((int) ($body['page_id'] ?? 0)) ?: null,
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
    /** Lift a user ban (admin or moderator). */
    public function liftBan(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth   = $request->getAttribute('auth_user');
        $body   = (array) $request->getParsedBody();

        if (!in_array($auth->role ?? '', ['admin', 'moderator'], true)) {
            return $this->json($response, ['error' => 'Moderator or admin required'], 403);
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
            'queue_pending'      => DB::table('comments')->where('status', 'escalated_human')->count(),
            'queue_reportable'   => DB::table('comments')->where('status', 'escalated_reportable')->count(),
            'hidden_total'       => DB::table('comments')->whereIn('status', ['hidden', 'hidden_reportable'])->count(),
            'appeals_pending'    => DB::table('appeal_records')->where('status', 'pending')->count(),
            'total_comments_30d' => DB::table('comments')->where('received_at', '>=', $since)->count(),
            'removed_30d'        => DB::table('comments')->where('status', 'removed')->where('processed_at', '>=', $since)->count(),
            'hidden_30d'         => DB::table('comments')->whereIn('status', ['hidden', 'hidden_reportable'])->where('processed_at', '>=', $since)->count(),
            'approved_30d'       => DB::table('comments')->where('status', 'approved')->where('processed_at', '>=', $since)->count(),
            'active_bans'        => DB::table('ban_records')
                ->where('ban_scope', 'user')
                ->where('is_active', 1)
                ->where(function ($q) { $q->whereNull('expires_at')->orWhere('expires_at', '>', date('Y-m-d H:i:s')); })
                ->count(),
            'by_stage'           => DB::table('moderation_log')
                ->where('created_at', '>=', $since)
                ->selectRaw('stage, COUNT(*) as count')
                ->groupBy('stage')
                ->pluck('count', 'stage'),
            'by_ai_decision'     => DB::table('moderation_log')
                ->where('created_at', '>=', $since)
                ->whereIn('stage', ['haiku', 'sonnet'])
                ->selectRaw('ai_decision, COUNT(*) as count')
                ->groupBy('ai_decision')
                ->pluck('count', 'ai_decision'),
            // Sonnet sub-calls: chiamate Sonnet che NON aggiornano lo stage del
            // log (perché il log porta lo stage della MODERAZIONE, non delle
            // sub-call). Contiamo righe con confidence > 0 sui due flussi.
            'sonnet_subcalls' => [
                'fact_check'   => DB::table('moderation_log')
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('ai_fact_check_confidence')
                    ->count(),
                'whataboutism' => DB::table('moderation_log')
                    ->where('created_at', '>=', $since)
                    ->whereNotNull('ai_whataboutism_confidence')
                    ->count(),
            ],
        ];

        // Pad by_stage con tutti gli stage noti (anche a 0) così il chart
        // mostra sempre Haiku / Sonnet / Umano / Sistema invece di nascondere
        // le voci assenti.
        //
        // NOTA: pluck() ritorna un'Illuminate\Support\Collection. Usare ->all()
        // (NON il cast (array)$collection) perché il cast PHP esporrebbe anche
        // le proprietà interne dell'oggetto (*items, *escapeWhenCastingToString,
        // ecc.) come chiavi, mascherando i dati reali nel JSON.
        $byStage = $data['by_stage']->all();
        foreach (['haiku', 'sonnet', 'human', 'system'] as $st) {
            if (!array_key_exists($st, $byStage)) {
                $byStage[$st] = 0;
            }
        }
        // Cast a int per uniformità (pluck può ritornare stringhe da COUNT(*)).
        $data['by_stage'] = array_map('intval', $byStage);

        // Stesso problema su by_ai_decision: serializziamolo a array puro
        // così il frontend riceve { allow: N, hide: N, ... } anziché un oggetto Collection.
        $data['by_ai_decision'] = array_map('intval', $data['by_ai_decision']->all());

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

    // ── GET /api/bans  ──────────────────────────────────────────────
    /**
     * Active user bans with full profile summary.
     * Query params: limit, page, violations (1|2|3|4+|all)
     */
    public function banList(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->ban->cleanupExpiredBans();

        $params     = $request->getQueryParams();
        $limit      = min((int) ($params['limit'] ?? 25), 100);
        $page       = max(1, (int) ($params['page'] ?? 1));
        $violations = $params['violations'] ?? 'all';

        $query = DB::table('ban_records as b')
            ->join('social_users as su', 'su.id', '=', 'b.social_user_id')
            ->leftJoin('connected_pages as cp', 'cp.id', '=', 'b.page_id')
            ->leftJoin('admin_users as au', 'au.id', '=', 'b.admin_user_id')
            ->leftJoin('comments as c', 'c.id', '=', 'b.trigger_comment_id')
            ->where('b.is_active', 1)
            ->where('b.ban_scope', 'user')
            ->where(function ($q) {
                $q->whereNull('b.expires_at')
                  ->orWhere('b.expires_at', '>', date('Y-m-d H:i:s'));
            });

        if ($violations !== 'all') {
            if ($violations === '4+') {
                $query->where('su.violation_count', '>=', 4);
            } else {
                $query->where('su.violation_count', (int) $violations);
            }
        }

        $total = (clone $query)->count();

        $items = $query
            ->select([
                'b.id as ban_id',
                'b.ban_type',
                'b.decided_by',
                'b.reason',
                'b.categories',
                'b.expires_at',
                'b.created_at as banned_at',
                'su.id as social_user_id',
                'su.platform_user_id',
                'su.display_name',
                'su.violation_count',
                'su.ban_status',
                'cp.page_name',
                'cp.page_id as facebook_page_id',
                'au.name as banned_by_name',
                'c.content as trigger_comment',
            ])
            ->orderByDesc('su.violation_count')
            ->orderByDesc('b.created_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['categories']   = json_decode($arr['categories'] ?? '[]', true);
                $arr['is_permanent'] = is_null($arr['expires_at']);
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

    // ── GET /api/bans/history  ───────────────────────────────────────
    /** Full ban history including lifted bans, paginated. */
    public function banHistory(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params = $request->getQueryParams();
        $limit  = min((int) ($params['limit'] ?? 25), 100);
        $page   = max(1, (int) ($params['page'] ?? 1));

        $total = DB::table('ban_records')->where('ban_scope', 'user')->count();

        $items = DB::table('ban_records as b')
            ->join('social_users as su', 'su.id', '=', 'b.social_user_id')
            ->leftJoin('connected_pages as cp', 'cp.id', '=', 'b.page_id')
            ->leftJoin('admin_users as au', 'au.id', '=', 'b.admin_user_id')
            ->where('b.ban_scope', 'user')
            ->select([
                'b.id as ban_id', 'b.ban_type', 'b.decided_by', 'b.reason',
                'b.categories', 'b.expires_at', 'b.is_active', 'b.created_at as banned_at',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count',
                'cp.page_name', 'au.name as banned_by_name',
            ])
            ->orderByDesc('b.created_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['categories'] = json_decode($arr['categories'] ?? '[]', true);
                return $arr;
            })
            ->toArray();

        return $this->json($response, compact('total', 'page', 'items') + ['per_page' => $limit]);
    }

    // ── GET /api/bans/comments  ──────────────────────────────────────
    /**
     * All removed comments (AI + human) with full moderation context.
     * Query params: limit, page, decided_by (ai|human|all), page_id
     */
    public function bannedComments(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params     = $request->getQueryParams();
        $limit      = min((int) ($params['limit'] ?? 25), 100);
        $page       = max(1, (int) ($params['page'] ?? 1));
        $decidedBy  = $params['decided_by'] ?? 'all';
        $pageFilter = (int) ($params['page_id'] ?? 0);
        $signal     = $params['signal']     ?? 'all';
        $category   = $params['category']   ?? '';

        $query = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->leftJoin('admin_users as au', 'au.id', '=', 'ml.human_user_id')
            ->where('c.status', 'removed');

        if ($decidedBy === 'ai') {
            $query->whereIn('ml.stage', ['haiku', 'sonnet']);
        } elseif ($decidedBy === 'human') {
            $query->where('ml.stage', 'human');
        }

        if ($pageFilter > 0) {
            $query->where('c.page_id', $pageFilter);
        }

        if ($signal === 'fact_check') {
            $query->where('ml.ai_fact_check_suggested', 1);
        } elseif ($signal === 'whataboutism') {
            $query->where('ml.ai_whataboutism_suggested', 1);
        } elseif ($signal === 'any') {
            $query->where(function ($q) {
                $q->where('ml.ai_fact_check_suggested', 1)
                  ->orWhere('ml.ai_whataboutism_suggested', 1);
            });
        } elseif ($signal === 'none') {
            $query->where('ml.ai_fact_check_suggested', 0)
                  ->where('ml.ai_whataboutism_suggested', 0);
        }

        if ($category !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $category) === 1) {
            $query->whereRaw('JSON_CONTAINS(ml.ai_categories, ?)', [json_encode($category)]);
        }

        $total = (clone $query)->count();

        $items = $query
            ->select([
                'c.id', 'c.content', 'c.received_at', 'c.processed_at',
                'c.platform_comment_id', 'c.platform_post_id',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count', 'su.ban_status',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.stage as ai_stage', 'ml.ai_decision', 'ml.ai_confidence',
                'ml.ai_reason', 'ml.ai_categories', 'ml.ai_severity',
                'ml.human_decision', 'ml.human_note', 'ml.human_decided_at',
                'ml.ai_fact_check_suggested', 'ml.ai_fact_check_confidence',
                'ml.ai_whataboutism_suggested', 'ml.ai_whataboutism_confidence',
                DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS decided_by_name"),
            ])
            ->orderByDesc('c.processed_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
                $arr['decided_by']    = $arr['human_decision'] ? 'human' : 'ai';
                return $arr;
            })
            ->toArray();

        return $this->json($response, compact('total', 'page', 'items') + ['per_page' => $limit]);
    }

    // ── GET /api/bans/stats  ─────────────────────────────────────────
    /** Aggregate stats for ban dashboard charts. */
    public function banStats(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $this->ban->cleanupExpiredBans();

        $since = date('Y-m-d H:i:s', strtotime('-30 days'));

        $byType = DB::table('ban_records')
            ->where('ban_scope', 'user')->where('is_active', 1)
            ->selectRaw('ban_type, COUNT(*) as count')->groupBy('ban_type')
            ->pluck('count', 'ban_type');

        // Flatten JSON categories from moderation_log into a frequency map.
        // Negative outcomes in this app are 'hidden' (comments are hidden, not
        // deleted) — include both so the breakdown isn't always empty.
        $categoryRows = DB::table('moderation_log')
            ->where('created_at', '>=', $since)
            ->whereIn('final_action', ['removed', 'hidden'])
            ->whereNotNull('ai_categories')
            ->pluck('ai_categories');

        $byCat = [];
        foreach ($categoryRows as $json) {
            foreach (json_decode($json, true) ?? [] as $cat) {
                $byCat[$cat] = ($byCat[$cat] ?? 0) + 1;
            }
        }
        arsort($byCat);

        $byDecider = DB::table('moderation_log')
            ->where('created_at', '>=', $since)->whereIn('final_action', ['removed', 'hidden'])
            ->selectRaw("SUM(CASE WHEN stage IN ('haiku','sonnet') THEN 1 ELSE 0 END) as ai,
                         SUM(CASE WHEN stage = 'human' THEN 1 ELSE 0 END) as human")
            ->first();

        $byPage = DB::table('ban_records as b')
            ->join('connected_pages as cp', 'cp.id', '=', 'b.page_id')
            ->where('b.ban_scope', 'user')->where('b.is_active', 1)
            ->selectRaw('cp.page_name, COUNT(*) as count')->groupBy('cp.page_name')
            ->pluck('count', 'page_name');

        $trend = DB::table('comments')
            ->where('status', 'removed')
            ->where('processed_at', '>=', date('Y-m-d H:i:s', strtotime('-14 days')))
            ->selectRaw('DATE(processed_at) as day, COUNT(*) as count')
            ->groupBy('day')->orderBy('day')
            ->pluck('count', 'day');

        return $this->json($response, [
            'active_bans_by_type' => $byType,
            'removed_by_category' => $byCat,
            'removed_by_decider'  => [
                'ai'    => (int) ($byDecider->ai    ?? 0),
                'human' => (int) ($byDecider->human ?? 0),
            ],
            'active_bans_by_page' => $byPage,
            'removal_trend_14d'   => $trend,
        ]);
    }

    // ── GET /api/bans/{id}/comments  ────────────────────────────────
    /** All flagged/removed comments for a specific banned user (drill-down). */
    public function userBannedComments(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $userId = (int) $args['id'];
        $user   = DB::table('social_users')->find($userId);

        if (!$user) {
            return $this->json($response, ['error' => 'User not found'], 404);
        }

        $comments = DB::table('comments as c')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->leftJoin('admin_users as au', 'au.id', '=', 'ml.human_user_id')
            ->where('c.social_user_id', $userId)
            ->whereIn('c.status', ['removed', 'escalated_human'])
            ->select([
                'c.id', 'c.content', 'c.status', 'c.received_at', 'cp.page_name',
                'ml.stage as ai_stage', 'ml.ai_decision', 'ml.ai_confidence',
                'ml.ai_reason', 'ml.ai_categories', 'ml.ai_severity',
                'ml.human_decision', 'ml.human_note',
                DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS decided_by_name"),
            ])
            ->orderByDesc('c.received_at')
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
                // Nome solo se ha deciso un umano (human_user_id valorizzato)
                $arr['decided_by_human'] = !is_null($arr['decided_by_name']);
                return $arr;
            })
            ->toArray();

        return $this->json($response, [
            'user'     => (array) $user,
            'comments' => $comments,
            'total'    => count($comments),
        ]);
    }

    // ── Dev mode helper ────────────────────────────────────────────
    private function isDevMode(): bool
    {
        try {
            $val = DB::table('app_settings')->where('key', 'dev_mode')->value('value');
            return (bool)(int)($val ?? '0');
        } catch (\Throwable) {
            return false;
        }
    }

    // ── GET /api/settings  ──────────────────────────────────────────
    /**
     * Returns current runtime settings (DB overrides take precedence over .env).
     * Admins receive the full settings payload.
     * Moderators receive only the fields needed to render the dashboard correctly.
     */
    public function getSettings(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth    = $request->getAttribute('auth_user');
        $isAdmin = ($auth->role ?? '') === 'admin';

        $rows = DB::table('app_settings')
            ->pluck('value', 'key')
            ->toArray();

        $defaults = [
            'haiku_confidence_threshold'   => $_ENV['HAIKU_CONFIDENCE_THRESHOLD']  ?? '0.80',
            'sonnet_confidence_threshold'  => $_ENV['SONNET_CONFIDENCE_THRESHOLD'] ?? '0.70',
            'recidivism_comment_ban_limit' => $_ENV['RECIDIVISM_COMMENT_BAN_LIMIT'] ?? '3',
            'dev_mode'                     => '0',
            'reason_max_words'             => '40',
            'removal_reply_enabled'        => '1',
            'removal_reply_template'       => 'Ciao @{nome}, il tuo commento è stato rimosso perché {reason}.',
            // Hide reply templates (PRO)
            'hide_reply_template'            => 'Ciao {nome}, il tuo commento è stato temporaneamente nascosto perché {reason}.\n\nSe ritieni che ci sia un errore, puoi richiedere una revisione: {appeal_url}',
            'hide_reportable_reply_template' => 'Ciao {nome}, il tuo commento è stato temporaneamente nascosto perché {reason}.\n\n⚠️ Il contenuto è stato segnalato per valutazione legale da parte della redazione.\n\nSe ritieni che ci sia un errore, puoi richiedere una revisione: {appeal_url}',
            'ban_warning_template'           => "Ciao {nome}, il tuo commento è stato rimosso perché non rispetta le nostre linee guida.\n\n⚠️ Ti informiamo che ulteriori violazioni comporteranno un ban temporaneo dalla pagina.",
            'ban_notification_template'      => "Ciao {nome}, il tuo commento è stato rimosso e il tuo account è stato temporaneamente sospeso dalla pagina per {durata}.\n\nPotrai tornare a commentare il {scadenza}.",
            // Data retention (PRO — 0 = disabled)
            'data_retention_days'          => '0',
            // Ban escalation levels
            'ban_level_1_hours'            => '1',
            'ban_level_2_days'             => '7',
            'ban_level_3_days'             => '30',
            // Timezone
            'app_timezone'                 => $_ENV['APP_TIMEZONE'] ?? 'Europe/Rome',
            // Privacy policy — shown in public/privacy.php
            'app_url'                      => $_ENV['APP_URL'] ?? '',
            'privacy_org_name'             => '',
            'privacy_org_address'          => '',
            'privacy_org_email'            => '',
            'privacy_org_country'          => '',
            'privacy_policy_date'          => date('d F Y') . ' / ' . date('d F Y'),
            'privacy_supervisory_authority'=> '',
        ];

        $merged = array_merge($defaults, $rows);
        $merged['dev_mode']              = (bool)(int)($merged['dev_mode'] ?? 0);
        $merged['reason_max_words']      = (int)($merged['reason_max_words'] ?? 40);
        $merged['removal_reply_enabled'] = (bool)(int)($merged['removal_reply_enabled'] ?? 1);
        $merged['data_retention_days']   = (int)($merged['data_retention_days'] ?? 0);

        // Moderators only receive the fields the dashboard strictly needs to function.
        if (!$isAdmin) {
            return $this->json($response, [
                'dev_mode'              => $merged['dev_mode'],
                'removal_reply_enabled' => $merged['removal_reply_enabled'],
                'app_timezone'          => $merged['app_timezone'],
                'role'                  => $auth->role ?? 'moderator',
            ]);
        }

        // Append license status for admin (no key exposed)
        $licenseStatus = $this->license->getStatus();

        return $this->json($response, array_merge($merged, [
            'role'    => 'admin',
            'license' => [
                'is_pro'       => $licenseStatus['is_pro'],
                'status'       => $licenseStatus['status'],
                'plan'         => $licenseStatus['plan'],
                'features'     => $licenseStatus['features'],
                'expires_at'   => $licenseStatus['expires_at'],
                'offline_mode' => $licenseStatus['offline_mode'],
            ],
        ]));
    }

    // ── PUT /api/settings  ──────────────────────────────────────────
    /** Update runtime settings (admin only). Values are validated before saving. */
    public function updateSettings(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $body  = (array) $request->getParsedBody();
        $isPro = $this->license->isPro();

        // ── FREE fields — always editable ───────────────────────────
        $allowed = [
            'haiku_confidence_threshold'   => fn($v) => max(0.01, min(1.0, (float) $v)),
            'sonnet_confidence_threshold'  => fn($v) => max(0.01, min(1.0, (float) $v)),
            'recidivism_comment_ban_limit' => fn($v) => max(1, (int) $v),
            'dev_mode'                     => fn($v) => $v ? '1' : '0',
            'reason_max_words'             => fn($v) => (string) max(10, min(200, (int) $v)),
            'removal_reply_enabled'        => fn($v) => $v ? '1' : '0',
            'removal_reply_template'       => fn($v) => substr(trim((string) $v), 0, 512),
            // Ban escalation
            'ban_level_1_hours'            => fn($v) => (string) max(1, (int) $v),
            'ban_level_2_days'             => fn($v) => (string) max(1, (int) $v),
            'ban_level_3_days'             => fn($v) => (string) max(1, (int) $v),
            // Timezone
            'app_timezone'                 => function ($v) {
                $tz = trim((string) $v);
                return in_array($tz, \DateTimeZone::listIdentifiers(), true) ? $tz : 'Europe/Rome';
            },
            // Privacy policy
            'app_url'                      => fn($v) => rtrim(filter_var(trim((string) $v), FILTER_SANITIZE_URL), '/'),
            'privacy_org_name'             => fn($v) => substr(trim((string) $v), 0, 255),
            'privacy_org_address'          => fn($v) => substr(trim((string) $v), 0, 512),
            'privacy_org_email'            => function ($v) {
                $email = trim((string) $v);
                return filter_var($email, FILTER_VALIDATE_EMAIL) ? $email : '';
            },
            'privacy_org_country'          => fn($v) => substr(trim((string) $v), 0, 255),
            'privacy_policy_date'          => fn($v) => substr(trim((string) $v), 0, 100),
            'privacy_supervisory_authority'=> fn($v) => substr(trim((string) $v), 0, 512),
        ];

        // ── PRO-only fields — gated behind license ───────────────────
        $proFields = [
            // feature: templates
            'hide_reply_template'                => fn($v) => substr(trim((string) $v), 0, 1024),
            'hide_reportable_reply_template'     => fn($v) => substr(trim((string) $v), 0, 1024),
            'ban_warning_template'               => fn($v) => substr(trim((string) $v), 0, 1024),
            'ban_notification_template'          => fn($v) => substr(trim((string) $v), 0, 1024),
            // feature: data_retention
            'data_retention_days'                => fn($v) => (string) max(0, (int) $v),
            // feature: fact_check
            'fact_check_auto_publish_threshold'  => fn($v) => (string) max(0.5, min(1.0, (float) $v)),
            // feature: whataboutism
            'whataboutism_auto_publish_threshold' => fn($v) => (string) max(0.5, min(1.0, (float) $v)),
        ];

        // Check if any Pro field was sent without a Pro license
        $proFieldsRequested = array_intersect_key($body, $proFields);
        if (!empty($proFieldsRequested) && !$isPro) {
            return $this->json($response, [
                'error'    => 'Pro license required to modify these settings',
                'fields'   => array_keys($proFieldsRequested),
                'is_pro'   => false,
            ], 403);
        }

        // Merge Pro fields into allowed list when licensed
        if ($isPro) {
            $allowed = array_merge($allowed, $proFields);
        }

        $saved = [];
        foreach ($allowed as $key => $sanitize) {
            if (!array_key_exists($key, $body)) continue;
            $value = (string) $sanitize($body[$key]);
            DB::table('app_settings')->updateOrInsert(
                ['key' => $key],
                ['value' => $value, 'updated_by' => $auth->sub, 'updated_at' => date('Y-m-d H:i:s')]
            );
            $saved[$key] = $value;
        }

        // Apply timezone immediately if changed (no restart required)
        if (isset($saved['app_timezone'])) {
            date_default_timezone_set($saved['app_timezone']);
        }

        return $this->json($response, ['saved' => true, 'settings' => $saved]);
    }

    // ── GET /api/comments/approved  ─────────────────────────────────
    /**
     * All approved comments (AI + human) with full moderation context.
     * Query params: limit, page, decided_by (ai|human|all)
     */
    public function approvedComments(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params    = $request->getQueryParams();
        $limit     = min((int) ($params['limit'] ?? 25), 100);
        $page      = max(1, (int) ($params['page'] ?? 1));
        $decidedBy = $params['decided_by'] ?? 'all';
        $signal    = $params['signal']     ?? 'all'; // all|fact_check|whataboutism|any|none
        $category  = $params['category']   ?? '';   // categoria AI da filtrare (vuoto = nessun filtro)

        $query = DB::table('comments as c')
            ->join('social_users as su', 'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp', 'cp.id', '=', 'c.page_id')
            ->leftJoin('moderation_log as ml', function ($join) {
                $join->on('ml.comment_id', '=', 'c.id')
                     ->whereRaw('ml.id = (SELECT MAX(id) FROM moderation_log WHERE comment_id = c.id)');
            })
            ->leftJoin('admin_users as au', 'au.id', '=', 'ml.human_user_id')
            ->where('c.status', 'approved');

        if ($decidedBy === 'ai') {
            $query->whereIn('ml.stage', ['haiku', 'sonnet'])->whereNull('ml.human_decision');
        } elseif ($decidedBy === 'human') {
            $query->where('ml.human_decision', 'allow');
        }

        if ($signal === 'fact_check') {
            $query->where('ml.ai_fact_check_suggested', 1);
        } elseif ($signal === 'whataboutism') {
            $query->where('ml.ai_whataboutism_suggested', 1);
        } elseif ($signal === 'any') {
            $query->where(function ($q) {
                $q->where('ml.ai_fact_check_suggested', 1)
                  ->orWhere('ml.ai_whataboutism_suggested', 1);
            });
        } elseif ($signal === 'none') {
            $query->where('ml.ai_fact_check_suggested', 0)
                  ->where('ml.ai_whataboutism_suggested', 0);
        }

        // Filtro categoria AI via JSON_CONTAINS (MySQL 5.7+ / MariaDB 10.2.3+).
        // Sanitizziamo la stringa: solo categorie alfanumeriche+underscore così
        // l'input non può iniettare JSON o caratteri di escape.
        if ($category !== '' && preg_match('/^[a-z0-9_]{1,64}$/', $category) === 1) {
            $query->whereRaw('JSON_CONTAINS(ml.ai_categories, ?)', [json_encode($category)]);
        }

        $total = (clone $query)->count();

        $items = $query
            ->select([
                'c.id', 'c.content', 'c.received_at', 'c.processed_at',
                'c.platform_comment_id', 'c.platform_post_id',
                'su.id as social_user_id', 'su.display_name', 'su.violation_count',
                'cp.page_name', 'cp.page_id as facebook_page_id',
                'ml.stage as ai_stage', 'ml.ai_decision', 'ml.ai_confidence',
                'ml.ai_reason', 'ml.ai_categories', 'ml.ai_severity',
                'ml.human_decision', 'ml.human_note', 'ml.human_decided_at',
                'ml.final_action',
                'ml.ai_fact_check_suggested', 'ml.ai_fact_check_confidence',
                'ml.ai_whataboutism_suggested', 'ml.ai_whataboutism_confidence',
                DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS decided_by_name"),
            ])
            ->orderByDesc('c.processed_at')
            ->offset(($page - 1) * $limit)
            ->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
                $arr['decided_by']    = match(true) {
                    $arr['final_action'] === 'auto_fact_checked'         => 'ai_fact_check',
                    $arr['final_action'] === 'auto_whataboutism_replied' => 'ai_whataboutism',
                    $arr['human_decision'] === 'allow'                   => 'human',
                    default                                              => 'ai',
                };
                return $arr;
            })
            ->toArray();

        return $this->json($response, compact('total', 'page', 'items') + ['per_page' => $limit]);
    }

    // ── GET /api/export/moderation-log  ────────────────────────────
    /**
     * Export moderation log as CSV or JSON (PRO feature).
     * Query params:
     *   format    csv | json (default: csv)
     *   from      YYYY-MM-DD (optional)
     *   to        YYYY-MM-DD (optional)
     *   page_id   int (optional, filter by connected page)
     *   decision  allow|remove|hide|uncertain|reportable (optional)
     */
    public function exportModerationLog(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (!in_array($auth->role ?? '', ['admin', 'supervisor'], true)) {
            return $this->json($response, ['error' => 'Admin or supervisor required'], 403);
        }

        if (!$this->license->hasFeature('export_log')) {
            return $this->json($response, ['error' => 'Pro license required', 'feature' => 'export_log'], 403);
        }

        $params   = $request->getQueryParams();
        $format   = in_array($params['format'] ?? 'csv', ['csv', 'json'], true) ? ($params['format'] ?? 'csv') : 'csv';
        $from     = $params['from']     ?? null;
        $to       = $params['to']       ?? null;
        $pageId   = isset($params['page_id']) ? (int) $params['page_id'] : null;
        $decision = $params['decision'] ?? null;

        $query = DB::table('moderation_log as ml')
            ->join('comments as c',       'c.id',  '=', 'ml.comment_id')
            ->join('social_users as su',  'su.id', '=', 'c.social_user_id')
            ->join('connected_pages as cp','cp.id','=', 'c.page_id')
            ->join('policies as p',       'p.id',  '=', 'ml.policy_id')
            ->leftJoin('admin_users as au','au.id', '=', 'ml.human_user_id')
            ->select([
                'ml.id as log_id',
                'ml.stage',
                'ml.ai_decision',
                'ml.ai_confidence',
                'ml.ai_reason',
                'ml.ai_categories',
                'ml.ai_severity',
                'ml.human_decision',
                'ml.human_note',
                'ml.human_decided_at',
                'ml.final_action',
                'ml.ai_model',
                'ml.ai_latency_ms',
                'ml.ai_fact_check_suggested',
                'ml.ai_fact_check_confidence',
                'ml.ai_whataboutism_suggested',
                'ml.ai_whataboutism_confidence',
                'ml.created_at as moderated_at',
                'c.id as comment_id',
                'c.content as comment_text',
                'c.received_at',
                'c.platform_comment_id',
                'su.display_name as commenter_name',
                'su.platform_user_id',
                'su.violation_count',
                'cp.page_name',
                'cp.page_id as facebook_page_id',
                'p.name as policy_name',
                'p.version as policy_version',
                DB::raw("COALESCE(NULLIF(TRIM(au.name), ''), au.email) AS reviewed_by"),
            ]);

        if ($from)     $query->where('ml.created_at', '>=', $from . ' 00:00:00');
        if ($to)       $query->where('ml.created_at', '<=', $to   . ' 23:59:59');
        if ($pageId)   $query->where('c.page_id', $pageId);
        if ($decision) $query->where('ml.ai_decision', $decision);

        $rows = $query->orderByDesc('ml.created_at')->get()->map(function ($row) {
            $arr = (array) $row;
            $arr['ai_categories'] = json_decode($arr['ai_categories'] ?? '[]', true);
            if (is_array($arr['ai_categories'])) {
                $arr['ai_categories'] = implode(';', $arr['ai_categories']);
            }
            return $arr;
        })->toArray();

        $filename = 'moderation-log-' . date('Y-m-d');

        if ($format === 'json') {
            $body = json_encode($rows, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
            $response->getBody()->write($body);
            return $response
                ->withHeader('Content-Type', 'application/json')
                ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}.json\"")
                ->withStatus(200);
        }

        // CSV output
        ob_start();
        $out = fopen('php://output', 'w');
        if (!empty($rows)) {
            fputcsv($out, array_keys($rows[0]));
            foreach ($rows as $row) {
                fputcsv($out, array_values($row));
            }
        }
        fclose($out);
        $csv = ob_get_clean();

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', "attachment; filename=\"{$filename}.csv\"")
            ->withStatus(200);
    }

    // ── GET /api/registro-trattamenti  ──────────────────────────────
    /**
     * Genera il Registro dei Trattamenti ai sensi dell'art. 30 GDPR.
     * Admin only. Restituisce HTML scaricabile come documento.
     * Tutti i dati sono letti dal DB in tempo reale.
     */
    public function registroTrattamenti(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (!in_array($auth->role ?? '', ['admin', 'supervisor'], true)) {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        // Leggi settings
        $settings = DB::table('app_settings')->pluck('value', 'key')->toArray();
        $orgName    = $settings['privacy_org_name']    ?? '[Titolare non configurato]';
        $orgAddress = $settings['privacy_org_address'] ?? '[Indirizzo non configurato]';
        $orgEmail   = $settings['privacy_org_email']   ?? '[Email non configurata]';
        $appUrl     = rtrim($settings['app_url']       ?? '', '/');
        $today      = date('d/m/Y');

        // Conta record per contesto
        $totComments = DB::table('comments')->count();
        $totUsers    = DB::table('social_users')->count();
        $totBans     = DB::table('ban_records')->where('is_active', 1)->count();
        $totPages    = DB::table('connected_pages')->where('is_active', 1)->count();
        $totAdmins   = DB::table('admin_users')->where('is_active', 1)->count();

        // Policy attiva
        $policy = DB::table('policies')->where('is_active', 1)->first();
        $policyName = $policy ? "{$policy->name} v{$policy->version}" : 'N/D';

        $vars = compact(
            'orgName','orgAddress','orgEmail','appUrl','today',
            'totComments','totUsers','totBans','totPages','totAdmins',
            'policyName'
        );

        ob_start();
        extract($vars);
        require __DIR__ . '/../../public/registro.php';
        $html = ob_get_clean();

        $response->getBody()->write($html);
        return $response
            ->withHeader('Content-Type', 'text/html; charset=utf-8')
            ->withHeader('Content-Disposition', 'inline; filename="registro-trattamenti-' . date('Y-m-d') . '.html"')
            ->withStatus(200);
    }

    // ── GET /api/retention/status  ───────────────────────────────────
    /**
     * Returns the configured retention window and the last cron execution.
     * Admin-only.
     */
    public function retentionStatus(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }

        $days = (int) (DB::table('app_settings')->where('key', 'data_retention_days')->value('value') ?? 0);
        $lastRun = (new RetentionService)->lastRun();

        return $this->json($response, [
            'retention_days' => $days,
            'enabled'        => $days > 0,
            'last_run'       => $lastRun,
        ]);
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