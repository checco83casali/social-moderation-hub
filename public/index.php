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
use ModerationHub\Middleware\AuthMiddleware;
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
// Must be added BEFORE routing so OPTIONS requests never reach the router
$app->add(function ($request, $handler) {
    // Handle OPTIONS preflight immediately — never pass to router
    if ($request->getMethod() === 'OPTIONS') {
        $response = new Response();
        return $response
            ->withHeader('Access-Control-Allow-Origin', '*')
            ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS')
            ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
            ->withStatus(200);
    }

    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $_ENV['APP_URL'] ?? '*')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

$app->addRoutingMiddleware();

$app->addErrorMiddleware(
    displayErrorDetails: ($_ENV['APP_ENV'] ?? 'production') === 'development',
    logErrors:           true,
    logErrorDetails:     true,
);

// ── Auth middleware factory ────────────────────────────────────────
$auth = fn() => new AuthMiddleware($container->get(OAuthService::class));

// ── Routes ────────────────────────────────────────────────────────

// Public: OAuth login (GET redirect + GET/POST callback for Microsoft)
$app->get('/auth/{provider}',          [AuthController::class, 'redirect']);
$app->get('/auth/{provider}/callback', [AuthController::class, 'callback']);
$app->post('/auth/{provider}/callback',[AuthController::class, 'callback']);

// Public: local (username + password) login
$app->post('/auth/local',              [AuthController::class, 'localLogin']);

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
// Public: Meta webhook (signature-verified internally)
// GET: inline handler with trim + diagnostic log (see logs/webhook_verify.log)
$app->get('/webhook/meta', function ($request, $response) {
    $params   = $request->getQueryParams();
    $expected = trim($_ENV['META_WEBHOOK_VERIFY_TOKEN'] ?? $_ENV['APP_SECRET'] ?? '');
    $received = trim((string)($params['hub_verify_token'] ?? ''));
    $mode     = (string)($params['hub_mode'] ?? '');

    // Diagnostic log — remove once verification passes
    @file_put_contents(
        __DIR__ . '/../logs/webhook_verify.log',
        sprintf(
            "[%s] mode=%s exp_len=%d recv_len=%d exp_hash=%s recv_hash=%s match=%s\n",
            date('c'),
            $mode,
            strlen($expected),
            strlen($received),
            substr(sha1($expected), 0, 8),
            substr(sha1($received), 0, 8),
            hash_equals($expected, $received) ? 'YES' : 'NO'
        ),
        FILE_APPEND
    );

    if ($mode === 'subscribe' && $expected !== '' && hash_equals($expected, $received)) {
        $response->getBody()->write((string)($params['hub_challenge'] ?? ''));
        return $response->withStatus(200);
    }
    $response->getBody()->write('Forbidden');
    return $response->withStatus(403);
});
$app->post('/webhook/meta', [WebhookController::class, 'receive']);

// Protected: API (requires valid JWT)
$app->group('/api', function ($group) {

    // Auth
    $group->get('/me',                         [AuthController::class, 'me']);
    $group->post('/me/password',               [AuthController::class, 'setPassword']);

    // Moderation queue & decisions
    $group->get('/queue/reportable',           [ModerationController::class, 'reportableQueue']);
    $group->get('/queue',                      [ModerationController::class, 'queue']);
    $group->post('/comments/{id}/decide',      [ModerationController::class, 'decide']);
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