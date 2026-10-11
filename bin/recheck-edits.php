#!/usr/bin/env php
<?php
/**
 * Rianalisi dei commenti modificati — CLI entry point.
 *
 * Un commento approvato o in coda che l'autore modifica viene rivalutato dall'AI dopo 5 minuti
 * di quiete, solo se il testo è davvero cambiato. Idempotente.
 *
 * Schedula con cron ogni minuto:
 *   * * * * * /usr/bin/php /path/to/social-moderation-hub/bin/recheck-edits.php >> /path/to/social-moderation-hub/logs/recheck.log 2>&1
 */
declare(strict_types=1);

use DI\ContainerBuilder;
use ModerationHub\Services\ModerationService;

require __DIR__ . '/../vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

    $builder = new ContainerBuilder;
    (require __DIR__ . '/../src/Config/container.php')($builder);
    $container = $builder->build();
    $container->get('db');

    $r = $container->get(ModerationService::class)->recheckEditedComments();
    if ($r['checked'] > 0) {
        echo '[' . date('Y-m-d H:i:s') . "] recheck: {$r['checked']} controllati, {$r['reanalysed']} rianalizzati, "
            . "{$r['unchanged']} invariati, {$r['failed']} falliti\n";
    }
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] recheck FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
