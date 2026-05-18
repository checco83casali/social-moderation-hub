<?php
// src/Controllers/WebhookController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\MetaGraphService;
use ModerationHub\Services\ModerationService;
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
        if (!$this->meta->validateSignature($rawBody, $signature)) {
            $this->logger?->warning('Webhook: invalid signature');
            $response->getBody()->write(json_encode(['error' => 'invalid signature']));
            return $response->withHeader('Content-Type', 'application/json')->withStatus(403);
        }

        $payload = json_decode($rawBody, true);
        if (!is_array($payload)) {
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

        foreach ($comments as $comment) {
            $page = DB::table('connected_pages')
                ->where('page_id', $comment['page_id'])
                ->where('is_active', 1)
                ->first();

            if (!$page) {
                continue;
            }

            try {
                $result = $this->moderation->processWebhookComment($comment, (array) $page);
                $this->logger?->info("Moderated comment", $result);
            } catch (\Throwable $e) {
                $this->logger?->error("Moderation failed: " . $e->getMessage());
                DB::table('webhook_events')->where('id', $eventId)->update([
                    'error' => $e->getMessage(),
                ]);
            }
        }

        DB::table('webhook_events')->where('id', $eventId)->update(['processed' => 1]);

        // Meta requires a 200 response quickly
        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(200);
    }
}
