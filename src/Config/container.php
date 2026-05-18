<?php
// src/Config/container.php
declare(strict_types=1);

use DI\ContainerBuilder;
use Monolog\Handler\StreamHandler;
use Monolog\Logger;
use ModerationHub\Services\ModerationService;
use ModerationHub\Services\ClaudeService;
use ModerationHub\Services\MetaGraphService;
use ModerationHub\Services\BanService;
use ModerationHub\Services\OAuthService;
use ModerationHub\Services\LicenseService;
use Illuminate\Database\Capsule\Manager as Capsule;

return function (ContainerBuilder $builder) {

    $builder->addDefinitions([

        // ── Database (Eloquent via Illuminate) ─────────────────────────
        'db' => function () {
            $capsule = new Capsule;
            $capsule->addConnection([
                'driver'    => 'mysql',
                'host'      => $_ENV['DB_HOST'],
                'port'      => $_ENV['DB_PORT'] ?? 3306,
                'database'  => $_ENV['DB_DATABASE'],
                'username'  => $_ENV['DB_USERNAME'],
                'password'  => $_ENV['DB_PASSWORD'],
                'charset'   => $_ENV['DB_CHARSET'] ?? 'utf8mb4',
                'collation' => 'utf8mb4_unicode_ci',
                'prefix'    => '',
            ]);
            $capsule->setAsGlobal();
            $capsule->bootEloquent();
            return $capsule;
        },

        // ── Logger ─────────────────────────────────────────────────────
        Logger::class => function () {
            $log = new Logger('moderation-hub');
            $log->pushHandler(new StreamHandler(
                __DIR__ . '/../../logs/app.log',
                Logger::DEBUG
            ));
            return $log;
        },

        // ── Services ───────────────────────────────────────────────────
        ClaudeService::class => function (\DI\Container $c) {
            // DB-first: read from app_settings if already bootstrapped (runtime overrides)
            // Falls back to .env defaults if the table doesn't exist yet
            $haikuThreshold  = (float) ($_ENV['HAIKU_CONFIDENCE_THRESHOLD']  ?? 0.80);
            $sonnetThreshold = (float) ($_ENV['SONNET_CONFIDENCE_THRESHOLD'] ?? 0.70);
            try {
                $h = \Illuminate\Database\Capsule\Manager::table('app_settings')
                    ->where('key', 'haiku_confidence_threshold')->value('value');
                $s = \Illuminate\Database\Capsule\Manager::table('app_settings')
                    ->where('key', 'sonnet_confidence_threshold')->value('value');
                if ($h !== null) $haikuThreshold  = (float) $h;
                if ($s !== null) $sonnetThreshold = (float) $s;
            } catch (\Throwable) { /* table not yet migrated or DB not ready */ }

            return new ClaudeService(
                apiKey:          $_ENV['ANTHROPIC_API_KEY'],
                haikuThreshold:  $haikuThreshold,
                sonnetThreshold: $sonnetThreshold,
                license:         $c->get(LicenseService::class),
            );
        },

        MetaGraphService::class => function () {
            return new MetaGraphService(
                appId: $_ENV['META_APP_ID'],
                appSecret: $_ENV['META_APP_SECRET'],
            );
        },

        BanService::class => DI\autowire(BanService::class),
        ModerationService::class => DI\autowire(ModerationService::class),
        LicenseService::class => DI\autowire(LicenseService::class),

        OAuthService::class => function () {
            return new OAuthService(
                appUrl: $_ENV['APP_URL'],
                providers: [
                    'google' => [
                        'clientId'     => $_ENV['OAUTH_GOOGLE_CLIENT_ID'],
                        'clientSecret' => $_ENV['OAUTH_GOOGLE_CLIENT_SECRET'],
                    ],
                    'meta' => [
                        'clientId'     => $_ENV['OAUTH_META_CLIENT_ID'],
                        'clientSecret' => $_ENV['OAUTH_META_CLIENT_SECRET'],
                    ],
                    'microsoft' => [
                        'clientId'     => $_ENV['OAUTH_MICROSOFT_CLIENT_ID'],
                        'clientSecret' => $_ENV['OAUTH_MICROSOFT_CLIENT_SECRET'],
                    ],
                ]
            );
        },
    ]);
};