<?php
// src/Controllers/WebhookController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\MetaGraphService;
use ModerationHub\Services\ModerationService;
use ModerationHub\Services\WebhookDebug;
use Illuminate\Database\Capsule\Manager as DB;
use Monolog\Logger;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * Handles Meta webhook events (comment detection) and challenge verification.
 */
class WebhookController
{
    public function __construct(
        private readonly MetaGraphService  $meta,
        private readonly ModerationService $moderation,
        private ?Logger                    $logger = null,
    ) {}

    // ── GET /webhook/meta  (Meta hub verification) ─────────────────
    public function verify(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $params      = $request->getQueryParams();
        $verifyToken = $_ENV['META_WEBHOOK_VERIFY_TOKEN'] ?? $_ENV['APP_SECRET'];
        $challenge   = $this->meta->verifyWebhook($params, $verifyToken);

        if ($challenge === null) {
            $response->getBody()->write('Forbidden');
            return $response->withStatus(403);
        }

        $response->getBody()->write($challenge);
        return $response->withStatus(200);
    }

    // ── POST /webhook/meta  (incoming events) ──────────────────────
    public function receive(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $rawBody   = (string) $request->getBody();
        $signature = $request->getHeaderLine('X-Hub-Signature-256');

        // Validate signature
        $debug = WebhookDebug::isActive();

        if (!$this->meta->validateSignature($rawBody, $signature)) {
            $this->logger?->warning('Webhook: invalid signature');
            if ($debug) {
                $this->storeDebugEvent($request, $rawBody, 'invalid_signature', [
                    'result' => 'rifiutato: firma X-Hub-Signature-256 non valida o assente',
                ]);
            }
            $response->getBody()->write(json_encode(['error' => 'invalid signature']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
            if ($debug) {
                $this->storeDebugEvent($request, $rawBody, 'invalid_json', [
                    'result' => 'rifiutato: il corpo non è JSON valido',
                ]);
            }
            return $response->withStatus(400);
        }

        // Persist raw event (async processing would be ideal, but we process inline for simplicity)
        $eventId = DB::table('webhook_events')->insertGetId([
            'page_id'     => $payload['entry'][0]['id'] ?? null,
            'event_type'  => $payload['object'] ?? 'unknown',
            'payload'     => $rawBody,
            'processed'   => 0,
            'received_at' => date('Y-m-d H:i:s'),
        ]);

        // Extract and moderate comments
        $comments = $this->meta->parseWebhookComments($payload);
        $trace    = [];

        foreach ($comments as $comment) {
            $page = DB::table('connected_pages')
                ->where('page_id', $comment['page_id'])
                ->where('is_active', 1)
                ->first();

            if (!$page) {
                $trace[] = ['comment_id' => $comment['id'], 'page_id' => $comment['page_id'], 'result' => 'ignorato: pagina non collegata o non attiva'];
                continue;
            }

            try {
                $result = $this->moderation->processWebhookComment($comment, (array) $page);
                $this->logger?->info("Moderated comment", $result);
                $trace[] = ['comment_id' => $comment['id'], 'page_id' => $comment['page_id'], 'result' => $result];
            } catch (\Throwable $e) {
                $this->logger?->error("Moderation failed: " . $e->getMessage());
                $trace[] = ['comment_id' => $comment['id'], 'page_id' => $comment['page_id'], 'result' => 'errore: ' . $e->getMessage()];
                DB::table('webhook_events')->where('id', $eventId)->update([
                    'error' => $e->getMessage(),
                ]);
            }
        }

        $update = ['processed' => 1];
        if ($debug) {
            $update['debug'] = $this->debugJson($request, [
                'comments_parsed' => count($comments),
                'comments'        => $trace,
                'result'          => $comments ? 'elaborato' : 'nessun commento da moderare in questo evento (campo/tipo/verbo non gestito o commento della pagina stessa)',
            ]);
        }
        DB::table('webhook_events')->where('id', $eventId)->update($update);

        // Meta requires a 200 response quickly
        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }

    // ── Debug helpers ──────────────────────────────────────────────
    /** @param array<string, mixed> $extra */
    private function storeDebugEvent(ServerRequestInterface $request, string $rawBody, string $type, array $extra): void
    {
        try {
            DB::table('webhook_events')->insert([
                'page_id'     => null,
                'event_type'  => $type,
                'payload'     => substr($rawBody, 0, WebhookDebug::MAX_PAYLOAD),
                'processed'   => 0,
                'debug'       => $this->debugJson($request, $extra),
                'received_at' => date('Y-m-d H:i:s'),
            ]);
        } catch (\Throwable $e) {
            $this->logger?->error('Webhook debug store failed: ' . $e->getMessage());
        }
    }

    /** @param array<string, mixed> $extra */
    private function debugJson(ServerRequestInterface $request, array $extra): string
    {
        $server = $request->getServerParams();
        return (string) json_encode([
            'ip'      => $server['REMOTE_ADDR'] ?? null,
            'headers' => WebhookDebug::safeHeaders($request->getHeaders()),
        ] + $extra, JSON_UNESCAPED_UNICODE | JSON_INVALID_UTF8_SUBSTITUTE);
    }
}
