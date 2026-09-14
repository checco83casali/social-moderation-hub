#!/usr/bin/env php
<?php
/**
 * Data retention purge — CLI entry point.
 *
 * Reads `data_retention_days` (general) and `violation_retention_days`
 * (social users with a recorded violation/ban — falls back to the general
 * window if unset) from app_settings, and anonymises every row older than
 * the applicable threshold across comments / social_users / moderation_log
 * / appeal_records / webhook_events. Idempotent.
 *
 * Schedule via cron, e.g. every night at 03:00:
 *   0 3 * * * /usr/bin/php /path/to/social-moderation-hub/bin/retention-purge.php >> /path/to/social-moderation-hub/logs/retention.log 2>&1
 *
 * Run manually:
 *   php bin/retention-purge.php
 *
 * Exit codes:
 *   0  success (purge executed OR feature disabled)
 *   1  fatal error (bootstrap / DB / etc.)
 */
declare(strict_types=1);

use DI\ContainerBuilder;
use ModerationHub\Services\RetentionService;

require __DIR__ . '/../vendor/autoload.php';

try {
    // ── Env ────────────────────────────────────────────────────────────
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();

    // ── Timezone (consistent with web app) ────────────────────────────
    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

    // ── Container + DB bootstrap ──────────────────────────────────────
    $builder = new ContainerBuilder;
    (require __DIR__ . '/../src/Config/container.php')($builder);
    $container = $builder->build();
    $container->get('db');

    // ── Run ────────────────────────────────────────────────────────────
    $service = new RetentionService;
    $result  = $service->purge();

    $stamp = date('Y-m-d H:i:s');
    if ($result['skipped'] ?? false) {
        echo "[{$stamp}] retention: skipped — {$result['reason']}\n";
        exit(0);
    }

    $a = $result['anonymised'];
    $cutoffDisplay          = $result['cutoff'] !== null ? $result['cutoff'] : 'n/d';
    $violationCutoffDisplay = $result['violation_cutoff'] !== null ? $result['violation_cutoff'] : 'n/d';
    echo "[{$stamp}] retention OK — cutoff {$cutoffDisplay} ({$result['retention_days']} days), "
        . "violation_cutoff {$violationCutoffDisplay} ({$result['violation_retention_days']} days), "
        . "comments={$a['comments']} "
        . "social_users={$a['social_users']} "
        . "moderation_log={$a['moderation_log']} "
        . "appeal_records={$a['appeal_records']} "
        . "webhook_events={$a['webhook_events']} "
        . "duration={$result['duration_ms']}ms\n";
    exit(0);

} catch (\Throwable $e) {
    $stamp = date('Y-m-d H:i:s');
    fwrite(STDERR, "[{$stamp}] retention FAILED: " . $e->getMessage() . "\n");
    fwrite(STDERR, $e->getTraceAsString() . "\n");
    exit(1);
}
