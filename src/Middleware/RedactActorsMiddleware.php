<?php

declare(strict_types=1);

namespace ModerationHub\Middleware;

use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Stream;

/**
 * Solo gli admin possono vedere CHI ha eseguito un'azione. Per gli altri ruoli (supervisor,
 * moderator) toglie dalle risposte JSON dell'API nomi e id degli operatori: restano i dati
 * "umano / AI", ma non l'identità. Va aggiunto PRIMA di AuthMiddleware (così gira dopo).
 */
final class RedactActorsMiddleware implements MiddlewareInterface
{
    /** Chiavi che identificano un operatore. */
    public const ACTOR_KEYS = [
        'decided_by_name', 'banned_by_name', 'admin_name', 'reviewed_by', 'reviewer_name',
        'human_user_id', 'admin_user_id', 'decided_by_user_id', 'lifted_by', 'actor_id', 'actor_name',
    ];

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $response = $handler->handle($request);

        $auth = $request->getAttribute('auth_user');
        if (is_object($auth) && ($auth->role ?? '') === 'admin') {
            return $response;
        }
        if (!str_contains($response->getHeaderLine('Content-Type'), 'application/json')) {
            return $response;
        }

        $data = json_decode((string) $response->getBody(), true);
        if (!is_array($data)) {
            return $response;
        }

        $body = new Stream(fopen('php://temp', 'r+'));
        $body->write((string) json_encode(self::redact($data), JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT));
        $body->rewind();

        return $response->withBody($body)->withoutHeader('Content-Length');
    }

    /**
     * @param  array<mixed> $data
     * @return array<mixed>
     */
    public static function redact(array $data): array
    {
        foreach ($data as $key => $value) {
            if (is_string($key) && in_array($key, self::ACTOR_KEYS, true)) {
                $data[$key] = null;
            } elseif (is_array($value)) {
                $data[$key] = self::redact($value);
            }
        }
        return $data;
    }
}
