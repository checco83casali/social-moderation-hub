<?php
// src/Services/MigrationService.php
declare(strict_types=1);

namespace ModerationHub\Services;

use Illuminate\Database\Capsule\Manager as DB;

/**
 * Applica automaticamente le migrazioni SQL pendenti in database/migrations/,
 * chiamato da DeployController::pull() dopo ogni `git pull` riuscito.
 *
 * Traccia le migrazioni applicate in `schema_migrations` (auto-creata al
 * primo uso). 001_initial_schema.sql è idempotente (solo CREATE TABLE IF NOT
 * EXISTS) e viene sempre eseguita senza rischio. 002 e 003 sono invece ALTER
 * TABLE non idempotenti pensate per essere lanciate a mano una sola volta su
 * installazioni pre-esistenti: se il runner parte su un database che ha già
 * le tabelle applicative (installazione precedente all'introduzione di questo
 * runner) ma non ha ancora `schema_migrations`, verifica la presenza delle
 * colonne che quei file introducono e le marca come già applicate invece di
 * ri-eseguirle (altrimenti l'ALTER fallirebbe su colonna duplicata).
 *
 * Da 004 in poi ogni nuovo file .sql viene eseguito automaticamente una sola
 * volta, in ordine alfabetico, alla prima occasione utile.
 *
 * Parsing SQL minimale: rimuove le righe di commento (`-- ...`) e splitta il
 * resto su ';'. Sufficiente per le migrazioni di questo progetto (DDL/DML
 * semplice, nessun literal contenente ';'). Una migrazione che necessitasse
 * di un ';' dentro una stringa va applicata a mano e marcata con markApplied().
 */
class MigrationService
{
    private const DIR = __DIR__ . '/../../database/migrations';

    /** Migrazioni storiche precedenti al runner + colonna/marcatore che ne prova l'esito. */
    private const HISTORICAL_MARKERS = [
        '002_whataboutism.sql'  => "SHOW COLUMNS FROM `moderation_log` LIKE 'ai_whataboutism_suggested'",
        '003_temp_password.sql' => "SHOW COLUMNS FROM `admin_users` LIKE 'must_change_password'",
    ];

    /**
     * @return array{
     *     applied: string[],
     *     skipped_historical: string[],
     *     errors: array<string,string>,
     * }
     */
    public function applyPending(): array
    {
        $this->ensureMigrationsTable();

        $applied           = [];
        $skippedHistorical = [];
        $errors            = [];

        $files = glob(self::DIR . '/*.sql') ?: [];
        sort($files);

        $alreadyApplied = DB::table('schema_migrations')->pluck('migration')->all();

        // Bootstrap: schema_migrations è appena stata creata (era vuota) ma le
        // tabelle applicative esistono già → installazione pre-esistente al runner.
        $isPreexisting = empty($alreadyApplied) && $this->tableExists('admin_users');

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $alreadyApplied, true)) {
                continue;
            }

            if ($isPreexisting && isset(self::HISTORICAL_MARKERS[$name])
                && $this->markerSatisfied(self::HISTORICAL_MARKERS[$name])
            ) {
                $this->markApplied($name);
                $skippedHistorical[] = $name;
                continue;
            }

            try {
                $this->runFile($file);
                $this->markApplied($name);
                $applied[] = $name;
            } catch (\Throwable $e) {
                $errors[$name] = $e->getMessage();
                // Interrompe la sequenza: le migrazioni successive potrebbero
                // presumere applicata quella appena fallita.
                break;
            }
        }

        return [
            'applied'             => $applied,
            'skipped_historical'  => $skippedHistorical,
            'errors'              => $errors,
        ];
    }

    private function runFile(string $path): void
    {
        $sql = file_get_contents($path);
        if ($sql === false) {
            throw new \RuntimeException("Impossibile leggere {$path}");
        }

        $lines = array_filter(
            explode("\n", $sql),
            fn(string $l): bool => !str_starts_with(trim($l), '--'),
        );
        $clean = implode("\n", $lines);

        foreach (explode(';', $clean) as $statement) {
            $statement = trim($statement);
            if ($statement === '') continue;
            DB::statement($statement);
        }
    }

    private function markerSatisfied(string $checkSql): bool
    {
        return !empty(DB::select($checkSql));
    }

    private function tableExists(string $table): bool
    {
        return !empty(DB::select('SHOW TABLES LIKE ?', [$table]));
    }

    private function ensureMigrationsTable(): void
    {
        DB::statement(<<<SQL
            CREATE TABLE IF NOT EXISTS `schema_migrations` (
                `id`         INT UNSIGNED AUTO_INCREMENT PRIMARY KEY,
                `migration`  VARCHAR(191) NOT NULL UNIQUE,
                `applied_at` TIMESTAMP DEFAULT CURRENT_TIMESTAMP
            ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci
            SQL);
    }

    private function markApplied(string $name): void
    {
        DB::table('schema_migrations')->insertOrIgnore(['migration' => $name]);
    }
}
