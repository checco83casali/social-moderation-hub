<?php

declare(strict_types=1);

namespace ModerationHub\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use Illuminate\Database\Query\Builder;
use ModerationHub\Services\LicenseService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Registro di audit (solo admin, licenza Advanced): chi ha fatto cosa, in sola lettura.
 *   GET /api/audit          lista filtrabile e paginata
 *   GET /api/audit/export   CSV con gli stessi filtri (max 5000 righe)
 */
class AuditController
{
    private const EXPORT_MAX = 5000;

    public function __construct(private readonly LicenseService $license) {}

    /**
     * Solo admin e solo con licenza Advanced (il registro si SCRIVE sempre; qui si regola la lettura).
     *
     * @return array{0:bool, 1:ResponseInterface|null} [consentito, risposta di rifiuto]
     */
    private function adminOnly(ServerRequestInterface $request, Response $response): array
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return [false, $this->json($response, ['error' => 'Solo gli admin possono consultare il registro di audit.'], 403)];
        }
        if (!$this->license->canViewAudit()) {
            return [false, $this->json($response, ['error' => 'Pro license required', 'feature' => 'advanced_audit'], 403)];
        }
        return [true, null];
    }

    private function baseQuery(ServerRequestInterface $request): Builder
    {
        $p = $request->getQueryParams();

        $q = DB::table('audit_log as a')
            ->leftJoin('admin_users as u', 'u.id', '=', 'a.actor_id')
            ->leftJoin('comments as c', 'c.id', '=', 'a.comment_id');

        if (!empty($p['action'])) {
            $action = (string) $p['action'];
            // "comment" = tutte le azioni sui commenti (comment.hide, comment.approve, ...)
            str_contains($action, '.') ? $q->where('a.action', $action) : $q->where('a.action', 'like', $action . '.%');
        }
        if (!empty($p['actor_id']))       $q->where('a.actor_id', (int) $p['actor_id']);
        if (!empty($p['comment_id']))     $q->where('a.comment_id', (int) $p['comment_id']);
        if (!empty($p['social_user_id'])) $q->where('a.social_user_id', (int) $p['social_user_id']);
        if (!empty($p['from']) && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['from'])) $q->where('a.created_at', '>=', $p['from'] . ' 00:00:00');
        if (!empty($p['to'])   && preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $p['to']))   $q->where('a.created_at', '<=', $p['to'] . ' 23:59:59');

        return $q;
    }

    /** @return list<string|\Illuminate\Database\Query\Expression> */
    private function columns(): array
    {
        return [
            'a.id', 'a.created_at', 'a.actor_id', 'a.actor_role', 'a.action',
            'a.comment_id', 'a.social_user_id', 'a.page_id', 'a.note', 'a.details',
            DB::raw("COALESCE(NULLIF(TRIM(u.name), ''), u.email, a.actor_label) AS actor_name"),
            DB::raw("SUBSTR(c.content, 1, 100) AS comment_excerpt"),
        ];
    }

    // ── GET /api/audit ────────────────────────────────────────────────
    public function list(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        [$ok, $denied] = $this->adminOnly($request, $response);
        if (!$ok && $denied !== null) return $denied;

        $p     = $request->getQueryParams();
        $limit = max(1, min((int) ($p['limit'] ?? 50), 200));
        $page  = max(1, (int) ($p['page'] ?? 1));

        $total = (clone $this->baseQuery($request))->count();
        $items = $this->baseQuery($request)
            ->select($this->columns())
            ->orderByDesc('a.id')
            ->offset(($page - 1) * $limit)->limit($limit)
            ->get()
            ->map(function ($row) {
                $arr = (array) $row;
                $arr['details'] = $arr['details'] ? json_decode((string) $arr['details'], true) : null;
                return $arr;
            })->all();

        return $this->json($response, ['total' => $total, 'page' => $page, 'per_page' => $limit, 'items' => $items]);
    }

    // ── GET /api/audit/export ─────────────────────────────────────────
    public function export(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        [$ok, $denied] = $this->adminOnly($request, $response);
        if (!$ok && $denied !== null) return $denied;

        $rows = $this->baseQuery($request)->select($this->columns())
            ->orderByDesc('a.id')->limit(self::EXPORT_MAX)->get();

        $fh = fopen('php://temp', 'r+');
        fwrite($fh, "\xEF\xBB\xBF");   // BOM: Excel legge correttamente l'UTF-8
        fputcsv($fh, ['id', 'data', 'chi', 'ruolo', 'azione', 'commento', 'estratto_commento', 'utente_sociale', 'nota', 'dettagli'], ';', '"', '');
        foreach ($rows as $r) {
            fputcsv($fh, array_map([$this, 'csvSafe'], [
                $r->id, $r->created_at, $r->actor_name, $r->actor_role, $r->action,
                $r->comment_id, $r->comment_excerpt, $r->social_user_id, $r->note, $r->details,
            ]), ';', '"', '');
        }
        rewind($fh);
        $csv = (string) stream_get_contents($fh);
        fclose($fh);

        $response->getBody()->write($csv);
        return $response
            ->withHeader('Content-Type', 'text/csv; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="audit-' . date('Y-m-d') . '.csv"');
    }

    /** Evita l'iniezione di formule in Excel (celle che iniziano con = + - @). */
    private function csvSafe(mixed $v): string
    {
        $s = $v === null ? '' : (string) $v;
        return ($s !== '' && in_array($s[0], ['=', '+', '-', '@', "\t", "\r"], true)) ? "'" . $s : $s;
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
