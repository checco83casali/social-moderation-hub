<?php
// src/Controllers/GdprController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\GdprService;
use Illuminate\Database\Capsule\Manager as DB;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Evasione manuale delle richieste dell'interessato (artt. 15/17/20 GDPR):
 * ricerca, export e anonimizzazione dei dati di un utente social.
 * Riservato al ruolo admin — non a moderatori/supervisori, per limitare
 * l'accesso a export/cancellazione di dati altrui al minimo indispensabile.
 */
class GdprController
{
    public function __construct(
        private readonly GdprService $gdpr,
    ) {}

    // ── GET /api/gdpr/search?q=...  ───────────────────────────────────
    /** Cerca un utente per ID Facebook, ID interno o token di appello. */
    public function search(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $q = trim((string) ($request->getQueryParams()['q'] ?? ''));
        if ($q === '') {
            return $this->json($response, ['error' => 'Parametro q mancante'], 400);
        }

        $user = $this->gdpr->findSocialUser($q);
        if (!$user) {
            return $this->json($response, ['found' => false]);
        }

        $auth = $request->getAttribute('auth_user');
        $this->gdpr->logAction('search', (int) $user->id, (int) $auth->sub, "Ricerca: {$q}");

        $data = $this->gdpr->exportUserData((int) $user->id) ?? [];
        return $this->json($response, ['found' => true] + $data);
    }

    // ── GET /api/gdpr/export/{id}  ────────────────────────────────────
    /**
     * Scarica il bundle completo dei dati di un utente (artt. 15/20).
     * @param array<string,mixed> $args
     */
    public function export(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $socialUserId = (int) $args['id'];
        $data = $this->gdpr->exportUserData($socialUserId);
        if (!$data) {
            return $this->json($response, ['error' => 'Utente non trovato'], 404);
        }

        $auth = $request->getAttribute('auth_user');
        $this->gdpr->logAction('export', $socialUserId, (int) $auth->sub, 'Export dati (art. 15/20 GDPR)');

        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json; charset=utf-8')
            ->withHeader('Content-Disposition', 'attachment; filename="dsar-export-' . $socialUserId . '-' . date('Y-m-d') . '.json"');
    }

    // ── POST /api/gdpr/anonymise/{id}  ────────────────────────────────
    /**
     * Anonimizza irreversibilmente i dati di un utente social (art. 17).
     * Richiede conferma esplicita e motivazione nel body — mai un solo click.
     * Body: { confirm: true, reason: "..." }
     * @param array<string,mixed> $args
     */
    public function anonymise(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        $body   = (array) $request->getParsedBody();
        $reason = trim((string) ($body['reason'] ?? ''));

        if (($body['confirm'] ?? false) !== true) {
            return $this->json($response, ['error' => 'Conferma esplicita richiesta (confirm: true)'], 400);
        }
        if ($reason === '') {
            return $this->json($response, ['error' => 'Motivazione della richiesta obbligatoria'], 400);
        }

        $socialUserId = (int) $args['id'];
        if (!DB::table('social_users')->where('id', $socialUserId)->exists()) {
            return $this->json($response, ['error' => 'Utente non trovato'], 404);
        }

        $counts = $this->gdpr->anonymiseUser($socialUserId);

        $auth = $request->getAttribute('auth_user');
        $this->gdpr->logAction('anonymise', $socialUserId, (int) $auth->sub, $reason, $counts);

        return $this->json($response, ['anonymised' => $counts]);
    }

    // ── GET /api/gdpr/audit  ──────────────────────────────────────────
    /** Registro delle richieste GDPR evase (accountability art. 5.2). */
    public function auditLog(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdmin($request, $response)) return $deny;

        return $this->json($response, ['log' => $this->gdpr->getAuditLog()]);
    }

    // ──────────────────────────────────────────────────────────────────

    private function requireAdmin(ServerRequestInterface $request, Response $response): ?ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (($auth->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }
        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
