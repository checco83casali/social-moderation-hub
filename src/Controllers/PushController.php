<?php
// src/Controllers/PushController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use Illuminate\Database\Capsule\Manager as DB;
use ModerationHub\Services\PushService;
use ModerationHub\Services\WebPush;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/** Iscrizioni alle notifiche push (Web Push) del dispositivo corrente. */
class PushController
{
    // ── GET /api/push/key ───────────────────────────────────────────
    public function key(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        try {
            return $this->json($response, ['publicKey' => (new WebPush())->publicKey()]);
        } catch (\Throwable $e) {
            return $this->json($response, ['error' => 'Notifiche push non disponibili sul server'], 503);
        }
    }

    // ── POST /api/push/subscribe  { endpoint, keys:{p256dh,auth} } ──
    public function subscribe(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $uid  = (int) ($request->getAttribute('auth_user')->sub ?? 0);
        $body = (array) $request->getParsedBody();
        $endpoint = trim((string) ($body['endpoint'] ?? ''));
        $p256dh   = (string) ($body['keys']['p256dh'] ?? '');
        $auth     = (string) ($body['keys']['auth'] ?? '');

        if (!WebPush::isAllowedEndpoint($endpoint) || strlen($endpoint) > 1024) {
            return $this->json($response, ['error' => 'Endpoint non valido'], 422);
        }
        if (strlen(WebPush::b64uDecode($p256dh)) !== 65 || strlen(WebPush::b64uDecode($auth)) < 16) {
            return $this->json($response, ['error' => 'Chiavi non valide'], 422);
        }

        // Stesso dispositivo = stesso endpoint: se cambia utente la riga passa al nuovo.
        DB::table('push_subscriptions')->updateOrInsert(
            ['endpoint_hash' => hash('sha256', $endpoint)],
            [
                'user_id'    => $uid,
                'endpoint'   => $endpoint,
                'p256dh'     => $p256dh,
                'auth'       => $auth,
                'user_agent' => substr($request->getHeaderLine('User-Agent'), 0, 255),
            ],
        );
        return $this->json($response, ['ok' => true]);
    }

    // ── POST /api/push/unsubscribe  { endpoint } ────────────────────
    public function unsubscribe(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $uid      = (int) ($request->getAttribute('auth_user')->sub ?? 0);
        $endpoint = trim((string) (((array) $request->getParsedBody())['endpoint'] ?? ''));
        DB::table('push_subscriptions')
            ->where('endpoint_hash', hash('sha256', $endpoint))
            ->where('user_id', $uid)
            ->delete();
        return $this->json($response, ['ok' => true]);
    }

    // ── POST /api/push/test ─────────────────────────────────────────
    public function test(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $uid  = (int) ($request->getAttribute('auth_user')->sub ?? 0);
        $sent = (new PushService())->sendTest($uid);
        return $this->json($response, ['sent' => $sent]);
    }

    private function json(Response $response, mixed $data, int $status = 200): ResponseInterface
    {
        $response->getBody()->write(json_encode($data, JSON_UNESCAPED_UNICODE));
        return $response->withHeader('Content-Type', 'application/json')->withStatus($status);
    }
}
