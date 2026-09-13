#!/usr/bin/env php
<?php
/**
 * Database migration runner — CLI entry point.
 *
 * Applies every pending .sql file in database/migrations/ that has not
 * already been recorded in `schema_migrations`. Same logic used automatically
 * by the GitHub auto-deploy webhook (see DeployController::pull()) — this
 * script exists for manual runs / installations without the webhook enabled.
 *
 * Run manually:
 *   php bin/migrate.php
 *
 * Exit codes:
 *   0  success (migrations applied, or nothing pending)
 *   1  fatal error (bootstrap / DB / a migration failed)
 */
declare(strict_types=1);

use DI\ContainerBuilder;
use ModerationHub\Services\MigrationService;

require __DIR__ . '/../vendor/autoload.php';

try {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

    $builder = new ContainerBuilder;
    (require __DIR__ . '/../src/Config/container.php')($builder);
    $container = $builder->build();
    $container->get('db');

    $result = (new MigrationService)->applyPending();
    $stamp  = date('Y-m-d H:i:s');

    if (empty($result['applied']) && empty($result['skipped_historical']) && empty($result['errors'])) {
        echo "[{$stamp}] migrate: nulla da applicare\n";
        exit(0);
    }

    if (!empty($result['applied'])) {
        echo "[{$stamp}] migrate: applicate — " . implode(', ', $result['applied']) . "\n";
    }
    if (!empty($result['skipped_historical'])) {
        echo "[{$stamp}] migrate: già presenti (storiche, marcate senza rieseguire) — "
            . implode(', ', $result['skipped_historical']) . "\n";
    }
    if (!empty($result['errors'])) {
        foreach ($result['errors'] as $file => $msg) {
            fwrite(STDERR, "[{$stamp}] migrate FAILED — {$file}: {$msg}\n");
        }
        exit(1);
    }

    exit(0);

} catch (\Throwable $e) {
    $stamp = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$stamp}] migrate FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
