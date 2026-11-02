#!/usr/bin/env php
<?php
/**
 * Recupero UNA TANTUM delle menzioni arrivate prima della lista "Menzioni" (migrazione 014).
 *
 * Rilegge i payload grezzi in `webhook_events` (campo `mention`, post di un'altra pagina) e li inserisce
 * in `mentions` con la data di ricezione originale. Niente push, niente chiamate a Facebook: il testo che
 * il payload non conteneva resta vuoto. Si considerano solo i payload ancora dentro la finestra di
 * conservazione (gli altri sono già stati svuotati dal cron) e gli eventi con firma valida. Richiede la
 * licenza Enterprise. Idempotente. Dopo l'uso si può cancellare questo file.
 *
 *   php bin/backfill-mentions.php            # prova: mostra cosa farebbe, non scrive
 *   php bin/backfill-mentions.php --apply    # scrive
 *
 * Exit codes: 0 ok (o nulla da fare) · 1 errore
 */
declare(strict_types=1);

use DI\ContainerBuilder;
use Illuminate\Database\Capsule\Manager as DB;
use ModerationHub\Services\MentionService;
use ModerationHub\Services\MetaGraphService;

require __DIR__ . '/../vendor/autoload.php';

try {
    Dotenv\Dotenv::createImmutable(__DIR__ . '/..')->load();
    date_default_timezone_set($_ENV['APP_TIMEZONE'] ?? 'Europe/Rome');

    $builder = new ContainerBuilder;
    (require __DIR__ . '/../src/Config/container.php')($builder);
    $container = $builder->build();
    $container->get('db');

    $apply = in_array('--apply', $argv, true);
    $svc   = $container->get(MentionService::class);
    $meta  = $container->get(MetaGraphService::class);

    if (!$svc->isAvailable()) {
        echo "Licenza Enterprise (mentions) non attiva: niente da fare.\n";
        exit(0);
    }

    $days   = (int) (DB::table('app_settings')->where('key', 'data_retention_days')->value('value') ?? 0);
    $cutoff = $days > 0 ? date('Y-m-d H:i:s', strtotime("-{$days} days")) : null;

    $query = DB::table('webhook_events')
        ->where('payload', 'like', '%"mention"%')
        ->where('payload', '!=', '{}')
        ->whereNotIn('event_type', ['invalid_signature', 'invalid_json'])   // firma non verificata: non attendibili
        ->orderBy('id');
    if ($cutoff !== null) {
        $query->where('received_at', '>=', $cutoff);
    }

    $seen = $new = $existing = $skipped = 0;
    foreach ($query->get() as $event) {
        $payload = json_decode((string) $event->payload, true);
        if (!is_array($payload)) {
            continue;   // payload troncato o non JSON
        }
        foreach ($meta->parseWebhookMentions($payload) as $mention) {
            $seen++;
            $page = DB::table('connected_pages')->where('page_id', $mention['page_id'])->where('is_active', 1)->first();
            if (!$page) {
                $skipped++;
                continue;
            }
            if ($apply) {
                $svc->backfill($mention, (array) $page, (string) $event->received_at) ? $new++ : $existing++;
            } elseif (DB::table('mentions')->where('page_id', $page->id)->where('platform_comment_id', $mention['id'])->exists()) {
                $existing++;
            } else {
                $new++;
            }
        }
    }

    echo '[' . date('Y-m-d H:i:s') . '] ' . ($apply ? 'backfill' : 'PROVA (nulla scritto, usa --apply)')
        . ": menzioni trovate={$seen} nuove={$new} già presenti/aggiornate={$existing} pagina non collegata={$skipped}\n";
    exit(0);
} catch (\Throwable $e) {
    fwrite(STDERR, '[' . date('Y-m-d H:i:s') . '] backfill FAILED: ' . $e->getMessage() . "\n");
    exit(1);
}
