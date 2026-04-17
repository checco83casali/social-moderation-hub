<?php
// public/index.php
declare(strict_types=1);

use DI\ContainerBuilder;
use ModerationHub\Controllers\AuthController;
use ModerationHub\Controllers\ModerationController;
use ModerationHub\Controllers\PagesController;
use ModerationHub\Controllers\PolicyController;
use ModerationHub\Controllers\WebhookController;
use ModerationHub\Middleware\AuthMiddleware;
use ModerationHub\Services\OAuthService;
use Slim\Factory\AppFactory;

require __DIR__ . '/../vendor/autoload.php';

// ── Environment ────────────────────────────────────────────────────
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

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
$app->addRoutingMiddleware();

// CORS (adjust origins for production)
$app->add(function ($request, $handler) {
    $response = $handler->handle($request);
    return $response
        ->withHeader('Access-Control-Allow-Origin', $_ENV['APP_URL'] ?? '*')
        ->withHeader('Access-Control-Allow-Headers', 'Authorization, Content-Type')
        ->withHeader('Access-Control-Allow-Methods', 'GET, POST, PUT, DELETE, OPTIONS');
});

$app->options('/{routes:.+}', function ($request, $response) {
    return $response;
});

$app->addErrorMiddleware(
    displayErrorDetails: ($_ENV['APP_ENV'] ?? 'production') === 'development',
    logErrors:           true,
    logErrorDetails:     true,
);

// ── Auth middleware factories ──────────────────────────────────────
$auth = fn() => new AuthMiddleware($container->get(OAuthService::class));

// ── Routes ─────────────────────────────────────────────────────────

// Public: OAuth login
$app->get('/auth/{provider}',          [AuthController::class, 'redirect']);
$app->get('/auth/{provider}/callback', [AuthController::class, 'callback']);

// Public: Meta webhook (signature-verified internally)
$app->get('/webhook/meta',  [WebhookController::class, 'verify']);
$app->post('/webhook/meta', [WebhookController::class, 'receive']);

// Protected: API (requires valid JWT)
$app->group('/api', function ($group) {

    // Auth
    $group->get('/me', [AuthController::class, 'me']);

    // Moderation queue & decisions
    $group->get('/queue',                      [ModerationController::class, 'queue']);
    $group->post('/comments/{id}/decide',      [ModerationController::class, 'decide']);
    $group->get('/stats',                      [ModerationController::class, 'stats']);
    $group->get('/learning-data',              [ModerationController::class, 'learningData']);

    // User management
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
    $group->post('/pages/available',           [PagesController::class, 'available']);
    $group->post('/pages/connect',             [PagesController::class, 'connect']);
    $group->post('/pages/{id}/webhook/retry',  [PagesController::class, 'retryWebhook']);
    $group->put('/pages/{id}/toggle',          [PagesController::class, 'toggle']);
    $group->delete('/pages/{id}',              [PagesController::class, 'disconnect']);

})->add($auth());

$app->run();
