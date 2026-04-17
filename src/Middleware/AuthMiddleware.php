<?php
// src/Middleware/AuthMiddleware.php
declare(strict_types=1);

namespace ModerationHub\Middleware;

use ModerationHub\Services\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Psr\Http\Server\MiddlewareInterface;
use Psr\Http\Server\RequestHandlerInterface;
use Slim\Psr7\Response;

/**
 * Validates the Bearer JWT on every protected API request.
 * Attaches the decoded payload to the request as 'auth_user'.
 */
class AuthMiddleware implements MiddlewareInterface
{
    public function __construct(
        private readonly OAuthService $oauth,
        private readonly string       $requiredRole = 'moderator', // 'moderator' | 'admin'
    ) {}

    public function process(ServerRequestInterface $request, RequestHandlerInterface $handler): ResponseInterface
    {
        $authHeader = $request->getHeaderLine('Authorization');

        if (!str_starts_with($authHeader, 'Bearer ')) {
            return $this->unauthorized('Missing authorization header');
        }

        $token = substr($authHeader, 7);

        try {
            $payload = $this->oauth->verifyJwt($token);
        } catch (\Throwable) {
            return $this->unauthorized('Invalid or expired token');
        }

        // Role check
        $roles = ['moderator' => 1, 'admin' => 2];
        $required = $roles[$this->requiredRole] ?? 1;
        $actual   = $roles[$payload->role ?? 'moderator'] ?? 1;

        if ($actual < $required) {
            return $this->forbidden("Requires {$this->requiredRole} role");
        }

        // Inject into request attributes
        $request = $request->withAttribute('auth_user', $payload);

        return $handler->handle($request);
    }

    private function unauthorized(string $message): Response
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(401);
    }

    private function forbidden(string $message): Response
    {
        $response = new Response();
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus(403);
    }
}
