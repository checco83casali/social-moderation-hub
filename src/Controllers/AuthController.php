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
            ->withHeader('Location', "{$appUrl}/dashboard.html?token={$token}")
            ->withStatus(302);
    }

    // ── POST /auth/local  ────────────────────────────────────────────
    /** Local login with email + password. Returns JSON { token, user }. */
    public function localLogin(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $body     = (array) $request->getParsedBody();
        $email    = trim((string) ($body['email']    ?? ''));
        $password =       (string) ($body['password'] ?? '');

        if (empty($email) || empty($password)) {
            return $this->error($response, 'Email e password sono obbligatorie.', 400);
        }

        try {
            $result = $this->oauth->loginLocal($email, $password);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 401);
        }

        $response->getBody()->write(json_encode($result));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── GET /api/me  ─────────────────────────────────────────────────
    /** Return current user info from JWT, including whether a local password is set. */
    public function me(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        $response->getBody()->write(json_encode([
            'id'           => $auth->sub,
            'name'         => $auth->name,
            'email'        => $auth->email,
            'role'         => $auth->role,
            'has_password' => $this->oauth->hasPassword((int) $auth->sub),
        ]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── POST /api/me/password  ───────────────────────────────────────
    /** Set or change the local password for the currently authenticated user. */
    public function setPassword(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        $body = (array) $request->getParsedBody();

        $newPassword    = (string) ($body['password']         ?? '');
        $confirmPassword = (string) ($body['password_confirm'] ?? '');

        if (empty($newPassword)) {
            return $this->error($response, 'La password non può essere vuota.', 400);
        }
        if ($newPassword !== $confirmPassword) {
            return $this->error($response, 'Le password non coincidono.', 400);
        }

        try {
            $this->oauth->setPassword((int) $auth->sub, $newPassword);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        $response->getBody()->write(json_encode(['ok' => true]));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── GET /api/users/local  ───────────────────────────────────────
    /** List all local-password users (admin only). */
    public function listLocalUsers(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if ($auth->role !== 'admin') {
            return $this->error($response, 'Accesso negato.', 403);
        }

        $users = \Illuminate\Database\Capsule\Manager::table('admin_users')
            ->whereNotNull('password_hash')
            ->orderBy('name')
            ->get(['id', 'name', 'email', 'role', 'last_login_at', 'is_active'])
            ->toArray();

        $response->getBody()->write(json_encode(array_values($users)));
        return $response->withHeader('Content-Type', 'application/json');
    }

    // ── POST /api/users/local  ───────────────────────────────────────
    /** Create a new local user (admin only). Returns the one-time temp password. */
    public function createLocalUser(ServerRequestInterface $request, Response $response): ResponseInterface
    {
        $auth = $request->getAttribute('auth_user');
        if ($auth->role !== 'admin') {
            return $this->error($response, 'Accesso negato.', 403);
        }

        $body  = (array) $request->getParsedBody();
        $name  = trim((string) ($body['name']  ?? ''));
        $email = trim((string) ($body['email'] ?? ''));
        $role  =       (string) ($body['role']  ?? 'moderator');

        if (empty($name) || empty($email)) {
            return $this->error($response, 'Nome ed email sono obbligatori.', 400);
        }
        if (!filter_var($email, FILTER_VALIDATE_EMAIL)) {
            return $this->error($response, 'Email non valida.', 400);
        }
        if (!in_array($role, ['admin', 'moderator', 'supervisor'], true)) {
            $role = 'moderator';
        }

        $existing = \Illuminate\Database\Capsule\Manager::table('admin_users')
            ->where('email', $email)->first();
        if ($existing) {
            return $this->error($response, 'Esiste già un account con questa email.', 409);
        }

        try {
            $result = $this->oauth->setPasswordForNewUser($name, $email, $role);
        } catch (\Throwable $e) {
            return $this->error($response, $e->getMessage(), 422);
        }

        $response->getBody()->write(json_encode([
            'ok'            => true,
            'temp_password' => $result['temp_password'],
        ]));
        return $response->withHeader('Content-Type', 'application/json')->withStatus(201);
    }

    private function error(Response $response, string $message, int $status): ResponseInterface
    {
        $response->getBody()->write(json_encode(['error' => $message]));
        return $response
            ->withHeader('Content-Type', 'application/json')
            ->withStatus($status);
    }
}