<?php
// src/Controllers/AuthController.php
declare(strict_types=1);

namespace ModerationHub\Controllers;

use ModerationHub\Services\OAuthService;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Slim\Psr7\Response;

/**
 * OAuth2 login flow: redirect → callback → JWT
 */
class AuthController
{
    public function __construct(
        private readonly OAuthService $oauth,
    ) {}

    // ── GET /auth/{provider}  ────────────────────────────────────────
    /** Redirect to the OAuth provider. */
    public function redirect(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $providerName = $args['provider'];
        $provider     = $this->oauth->getProvider($providerName);

        $authUrl = $provider->getAuthorizationUrl();
        $_SESSION['oauth2_state'] = $provider->getState();

        return $response
            ->withHeader('Location', $authUrl)
            ->withStatus(302);
    }

    // ── GET /auth/{provider}/callback  ───────────────────────────────
    /** Handle the OAuth callback, issue JWT. */
    public function callback(ServerRequestInterface $request, Response $response, array $args): ResponseInterface
    {
        $params       = $request->getQueryParams();
        $providerName = $args['provider'];

        // CSRF state check
        $storedState = $_SESSION['oauth2_state'] ?? '';
        unset($_SESSION['oauth2_state']);

        if (empty($params['state']) || $params['state'] !== $storedState) {
            return $this->error($response, 'Invalid OAuth state (CSRF check failed)', 400);
        }

        if (isset($params['error'])) {
            return $this->error($response, $params['error_description'] ?? $params['error'], 401);
        }

        try {
            $result = $this->oauth->handleCallback($providerName, $params['code'], $params['state']);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 401);
        }

        // Redirect to frontend with JWT in URL fragment (SPA-friendly)
        $token   = urlencode($result['token']);
        $appUrl  = rtrim($_ENV['APP_URL'], '/');
        return $response
            ->withHeader('Location', "{$appUrl}/dashboard?token={$token}")
            ->withStatus(302);
    }

    // ── GET /api/me  ─────────────────────────────────────────────────
    /** Return current user info from JWT. */
    public function me(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        $response->getBody()->write(json_encode([
            'id'    => $auth->sub,
            'name'  => $auth->name,
            'email' => $auth->email,
            'role'  => $auth->role,
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    private function error(Response $response, string $message, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}
