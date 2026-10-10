<?php
// src/Controllers/AiTrainingController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use ModerationHub\Services\AiTrainingService;
use ModerationHub\Services\AuditService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Training AI (licenza Pro `ai_training`): stato, avvio/arresto della raccolta, rilancio
 * dell'analisi (admin) e note "postume" sui commenti già decisi (moderatori, supervisori, admin).
 */
class AiTrainingController
{
    public function __construct(private readonly AiTrainingService $training) {}

    // ── GET /api/ai-training  (tutti i ruoli: serve a mostrare/nascondere i comandi) ──
    public function status(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $isAdmin = (($request->getAttribute('auth_user')->role ?? '') === 'admin');
        return $this->json($response, $this->training->status($isAdmin));
    }

    // ── POST /api/ai-training  { enabled: bool, limit?: int }  (admin) ──
    public function toggle(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdminLicensed($request, $response)) return $deny;
        $auth    = $request->getAttribute('auth_user');
        $userId  = isset($auth->sub) ? (int) $auth->sub : null;
        $body    = (array) $request->getParsedBody();

        if (!empty($body['enabled'])) {
            $limit = (int) ($body['limit'] ?? 30);
            if ($limit < AiTrainingService::MIN_LIMIT || $limit > AiTrainingService::MAX_LIMIT) {
                return $this->json($response, ['error' => 'Il numero di note deve essere tra ' . AiTrainingService::MIN_LIMIT . ' e ' . AiTrainingService::MAX_LIMIT . '.'], 422);
            }
            $sessionId = $this->training->start($limit, $userId);
            AuditService::log($auth, 'ai_training.start', ['details' => ['session_id' => $sessionId, 'limit' => $limit]]);
        } else {
            $this->training->stop($userId);
            AuditService::log($auth, 'ai_training.stop');
        }
        return $this->json($response, $this->training->status(true));
    }

    // ── POST /api/ai-training/analyze  (admin) — rilancia l'analisi dell'ultima sessione ──
    public function analyze(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        if ($deny = $this->requireAdminLicensed($request, $response)) return $deny;
        $last = DB::table('ai_training_sessions')->orderByDesc('id')->first();
        if (!$last || !$this->training->retry((int) $last->id)) {
            return $this->json($response, ['error' => 'Servono almeno 3 note e una raccolta non in corso di analisi.'], 409);
        }
        return $this->json($response, $this->training->status(true), 202);
    }

    // ── POST /api/comments/{id}/training-note  { note }  (nota postuma) ──
    /** @param array<string,string> $args */
    public function addNote(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if (!in_array($auth->role ?? '', ['admin', 'supervisor', 'moderator'], true)) {
            return $this->json($response, ['error' => 'Non autorizzato'], 403);
        }
        if (!$this->training->licensed()) {
            return $this->json($response, ['error' => 'Pro license required', 'feature' => 'ai_training'], 403);
        }
        if (!$this->training->activeSession()) {
            return $this->json($response, ['error' => 'Il training AI non è attivo.'], 409);
        }

        $commentId = (int) $args['id'];
        $note      = trim((string) (((array) $request->getParsedBody())['note'] ?? ''));
        if (mb_strlen($note) < AiTrainingService::MIN_NOTE_CHARS) {
            return $this->json($response, ['error' => 'La nota deve avere almeno ' . AiTrainingService::MIN_NOTE_CHARS . ' caratteri.'], 422);
        }
        if (!DB::table('comments')->where('id', $commentId)->exists()) {
            return $this->json($response, ['error' => 'Commento non trovato'], 404);
        }
        if ($this->training->aiVerdict($commentId) === null) {
            return $this->json($response, ['error' => 'Questo commento non ha un verdetto dell\'AI da commentare.'], 409);
        }
        if ($this->training->hasNote($commentId)) {
            return $this->json($response, ['error' => 'Per questo commento esiste già una nota di addestramento.'], 409);
        }

        try {
            $r = $this->training->recordPosthumous($commentId, isset($auth->sub) ? (int) $auth->sub : null, $note);
        } catch (\DomainException $e) {
            return $this->json($response, ['error' => $e->getMessage()], 409);
        }
        AuditService::log($auth, 'ai_training.note', [
            'comment_id' => $commentId, 'note' => $note, 'details' => ['kind' => $r['kind'], 'posthumous' => true],
        ]);
        return $this->json($response, $r, 201);
    }

    private function requireAdminLicensed(ServerRequestInterface $request, Response $response): ?ResponseInterface
    {
        if (($request->getAttribute('auth_user')->role ?? '') !== 'admin') {
            return $this->json($response, ['error' => 'Admin required'], 403);
        }
        if (!$this->training->licensed()) {
            return $this->json($response, ['error' => 'Pro license required', 'feature' => 'ai_training'], 403);
        }
        return null;
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
