<?php
// public/index.php
declare(strict_types=1);

use DI\ContainerBuilder;
use ModerationHub\Controllers\AuthController;
use ModerationHub\Controllers\LicenseController;
use ModerationHub\Controllers\ModerationController;
use ModerationHub\Controllers\PagesController;
use ModerationHub\Controllers\PolicyController;
use ModerationHub\Controllers\WebhookController;
use ModerationHub\Controllers\DeployController;
use ModerationHub\Middleware\AuthMiddleware;
use ModerationHub\Middleware\AccessGuardMiddleware;
use ModerationHub\Services\OAuthService;
use Slim\Factory\AppFactory;
use Slim\Psr7\Response;

require __DIR__ . '/../vendor/autoload.php';

// ── Environment ────────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

// ── Timezone ───────────────────────────────────────────────────────
date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

// ── Session ────────────────────────────────────────────────────────
session_start();

// ── DI Container ───────────────────────────────────────────────────
$builder = new ContainerBuilder;
(require __DIR__ . '/../src/Config/container.php')($builder);
$container = $builder->build();

// Bootstrap DB connection
$container->get('db');

// ── Slim App ───────────────────────────────────────────────────────
AppFactory::setContainer($container);
$app = AppFactory::create();

$app->addBodyParsingMiddleware();

// ── CORS middleware ────────────────────────────────────────────────
// Must be added BEFORE routing so OPTIONS requests never reach the router.
// Origin is locked to APP_URL (the dashboard origin) — never a wildcard, so
// other sites cannot drive authenticated calls from a victim's browser.
$corsOrigin = $_ENV['APP_URL'] ?? '';
$app->add(function ($request, $handler) use ($corsOrigin) {
    // Handle OPTIONS preflight immediately — never pass to router
    if ($request->getMethod() === 'OPTIONS') {
        $response = new Response();
        return $response
            ->withHeader('Access-Control-Allow-Origin', $corsOrigin ?: 'null')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withHeader('Vary', 'Origin')
            ->withStatus(200);
    }

    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $corsOrigin ?: 'null')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
        ->withHeader('Vary', 'Origin');
});

// ── Network access guard (opt-in: internal IP allowlist + public-domain split)
// No-op unless INTERNAL_IP_ALLOWLIST or PUBLIC_DOMAIN are set in .env.
$app->add(new AccessGuardMiddleware());

$app->addRoutingMiddleware();

$errorMiddleware = $app->addErrorMiddleware(
    displayErrorDetails: ($_ENV['APP_ENV'] ?? 'production') === 'development',
    logErrors:           true,
    logErrorDetails:     true,
);

$errorMiddleware->setErrorHandler(
    \Slim\Exception\HttpNotFoundException::class,
    function ($request, $exception) use ($app): \Psr\Http\Message\ResponseInterface {
        $response = $app->getResponseFactory()->createResponse(404);
        $appUrl   = rtrim($_ENV['APP_URL'] ?? '', '/');
        $response->getBody()->write(<<<HTML
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width,initial-scale=1">
<title>404 — Pagina non trovata</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:'DM Sans',system-ui,sans-serif;background:#0e0f11;color:#e8eaf0;min-height:100vh;display:flex;align-items:center;justify-content:center;padding:24px}
  .card{background:#16181c;border:1px solid rgba(255,255,255,.07);border-radius:16px;padding:48px 40px;max-width:420px;width:100%;text-align:center}
  .code{font-size:72px;font-weight:700;color:rgba(255,255,255,.06);line-height:1;margin-bottom:8px}
  h1{font-size:20px;font-weight:600;margin-bottom:10px}
  p{font-size:13.5px;color:#7a7f8e;line-height:1.6;margin-bottom:28px}
  a{display:inline-block;padding:10px 24px;background:#4f8ef7;color:#fff;border-radius:10px;font-size:13.5px;font-weight:600;text-decoration:none}
  a:hover{background:#3a7de8}
</style>
</head>
<body>
<div class="card">
  <div class="code">404</div>
  <h1>Pagina non trovata</h1>
  <p>La risorsa che cerchi non esiste o è stata spostata.</p>
</div>
</body>
</html>
HTML);
        return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
    }
);

// ── Auth middleware factory ────────────────────────────────────────
$auth = fn() => new AuthMiddleware($container->get(OAuthService::class));

// ── Routes ────────────────────────────────────────────────────────

// Root: redirect alla dashboard (SPA) — evita 404 di Slim su "/".
$app->get('/', function ($request, $response) {
    return $response->withHeader('Location', '/dashboard.html')->withStatus(302);
});

// Public: static auth routes (must be registered before dynamic {provider} routes)
$app->post('/auth/local',              [AuthController::class, 'localLogin']);
$app->get('/auth/providers',           [AuthController::class, 'providers']);

// Public: OAuth login (GET redirect + GET/POST callback for Microsoft)
$app->get('/auth/{provider}',          [AuthController::class, 'redirect']);
$app->get('/auth/{provider}/callback', [AuthController::class, 'callback']);
$app->post('/auth/{provider}/callback',[AuthController::class, 'callback']);

// ── Public transparency endpoints (no auth required) ──────────────
$app->get('/public/policy', function ($request, $response) {
    ob_start();
    require __DIR__ . '/policy.php';
    $html = ob_get_clean();
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});

$app->get('/public/policy.json', function ($request, $response) {
    ob_start();
    $jsonMode = true;
    require __DIR__ . '/policy.php';
    $out = ob_get_clean();
    $response->getBody()->write($out);
    return $response->withHeader('Content-Type', 'application/json');
});

$app->get('/privacy', function ($request, $response) {
    ob_start();
    require __DIR__ . '/privacy.php';
    $html = ob_get_clean();
    $response->getBody()->write($html);
    return $response->withHeader('Content-Type', 'text/html; charset=utf-8');
});
// Public: Meta webhook (token-verified on GET, HMAC-signature-verified on POST)
$app->get('/webhook/meta',  [WebhookController::class, 'verify']);
$app->post('/webhook/meta', [WebhookController::class, 'receive']);

// Public: GitHub auto-deploy webhook (signature-verified internally).
// Accetta sia con sia senza slash finale: GitHub a volte appende uno '/'.
$app->post('/webhook/github',  [DeployController::class, 'pull']);
$app->post('/webhook/github/', [DeployController::class, 'pull']);

// Protected: API (requires valid JWT)
$app->group('/api', function ($group) {

    // Auth
    $group->get('/me',                         [AuthController::class, 'me']);
    $group->post('/me/password',               [AuthController::class, 'setPassword']);

    // Moderation queue & decisions
    $group->get('/queue/reportable',           [ModerationController::class, 'reportableQueue']);
    $group->get('/queue/reportable/archive',   [ModerationController::class, 'reportableArchive']);
    $group->get('/queue',                      [ModerationController::class, 'queue']);
    $group->post('/comments/{id}/decide',      [ModerationController::class, 'decide']);
    $group->post('/comments/{id}/report-legal',[ModerationController::class, 'reportLegal']);
    $group->get('/comments/{id}/legal-dossier',[ModerationController::class, 'legalDossier']);
    $group->post('/comments/{id}/reply',       [ModerationController::class, 'reply']);
    $group->get('/stats',                      [ModerationController::class, 'stats']);
    $group->get('/learning-data',              [ModerationController::class, 'learningData']);

    // User management
    $group->get('/users/local',                [AuthController::class, 'listLocalUsers']);  // admin
    $group->post('/users/local',               [AuthController::class, 'createLocalUser']); // admin
    $group->get('/users/{id}',                 [ModerationController::class, 'userDetail']);
    $group->post('/users/{id}/ban',            [ModerationController::class, 'banUser']);
    $group->delete('/users/{id}/ban',          [ModerationController::class, 'liftBan']);

    // Policies
    $group->get('/policies',                   [PolicyController::class, 'index']);
    $group->get('/policies/active',            [PolicyController::class, 'active']);
    $group->get('/policies/{id}',              [PolicyController::class, 'show']);
    $group->post('/policies',                  [PolicyController::class, 'create']);
    $group->put('/policies/{id}',              [PolicyController::class, 'update']);
    $group->post('/policies/{id}/activate',    [PolicyController::class, 'activate']);

    // Facebook Pages
    $group->get('/pages',                      [PagesController::class, 'index']);
    $group->get('/pages/login-config',         [PagesController::class, 'loginConfig']);
    $group->post('/pages/available',           [PagesController::class, 'available']);
    $group->post('/pages/connect',             [PagesController::class, 'connect']);
    $group->post('/pages/connect-direct',      [PagesController::class, 'connectDirect']);
    $group->post('/pages/connect-batch',       [PagesController::class, 'connectBatch']);
    $group->post('/pages/{id}/webhook/retry',  [PagesController::class, 'retryWebhook']);
    $group->put('/pages/{id}/toggle',          [PagesController::class, 'toggle']);
    $group->get('/pages/{id}/settings',        [PagesController::class, 'getPageSettings']);
    $group->put('/pages/{id}/settings',        [PagesController::class, 'updatePageSettings']);
    $group->delete('/pages/{id}',              [PagesController::class, 'disconnect']);

    // Export (PRO feature)
    $group->get('/export/moderation-log',      [ModerationController::class, 'exportModerationLog']);

    // Runtime settings (admin only for PUT)
    $group->get('/settings',                   [ModerationController::class, 'getSettings']);
    $group->put('/settings',                   [ModerationController::class, 'updateSettings']);

    // Data retention status (admin only)
    $group->get('/retention/status',           [ModerationController::class, 'retentionStatus']);

    // License (admin only for PUT/DELETE/POST)
    $group->get('/license',                    [LicenseController::class, 'status']);
    $group->put('/license',                    [LicenseController::class, 'activate']);
    $group->delete('/license',                 [LicenseController::class, 'deactivate']);
    $group->post('/license/refresh',           [LicenseController::class, 'refresh']);

    // Registro dei Trattamenti art. 30 GDPR (admin/supervisor)
    $group->get('/registro-trattamenti',       [ModerationController::class, 'registroTrattamenti']);

    // DPIA art. 35 GDPR (admin/supervisor)
    $group->get('/dpia',                       [ModerationController::class, 'exportDpia']);

    // Approved comments
    $group->get('/comments/approved',          [ModerationController::class, 'approvedComments']);

    // Hidden comments
    $group->get('/comments/hidden',            [ModerationController::class, 'hiddenComments']);

    // Appeals
    $group->get('/appeals',                    [ModerationController::class, 'appealQueue']);
    $group->post('/appeals/{id}/decide',       [ModerationController::class, 'decideAppeal']);

    // Ban analytics — dashboard "Utenti bannati" + "Commenti rimossi"
    // IMPORTANT: specific routes (/bans/history, /bans/comments, /bans/stats)
    // must be declared BEFORE the wildcard /bans/{id}/comments to avoid conflicts
    $group->get('/bans',                       [ModerationController::class, 'banList']);
    $group->get('/bans/history',               [ModerationController::class, 'banHistory']);
    $group->get('/bans/comments',              [ModerationController::class, 'bannedComments']);
    $group->get('/bans/stats',                 [ModerationController::class, 'banStats']);
    $group->get('/bans/{id}/comments',         [ModerationController::class, 'userBannedComments']);

})->add($auth());

$app->run();