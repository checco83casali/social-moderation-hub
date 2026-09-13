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
 * primo uso). 001_initial_schema.sql è idempotente (CREATE TABLE IF NOT
 * EXISTS + ON DUPLICATE KEY UPDATE / INSERT IGNORE sui seed) e viene sempre
 * eseguita senza rischio, anche se già applicata in precedenza. 002 e 003
 * sono invece ALTER TABLE non idempotenti: prima di eseguirle, il runner
 * verifica se la colonna che introducono esiste già (perché applicata a mano
 * in passato, o perché 001 la include ormai di serie) e in tal caso le marca
 * come applicate senza ri-eseguirle — altrimenti l'ALTER fallirebbe su
 * colonna duplicata.
 *
 * Da 004 in poi ogni nuovo file .sql viene eseguito automaticamente una sola
 * volta, in ordine alfabetico, alla prima occasione utile.
 *
 * Il parser (splitStatements) rispetta i literal di stringa: un ';' o una
 * riga '-- ...' dentro un valore stringa (es. il system prompt seminato in
 * 001_initial_schema.sql) non viene scambiato per fine statement o commento.
 */
class MigrationService
{
    private const DIR = __DIR__ . '/../../database/migrations';

    /** Migrazioni storiche precedenti al runner + [tabella, colonna] che ne prova l'esito. */
    private const HISTORICAL_MARKERS = [
        '002_whataboutism.sql'  => ['moderation_log', 'ai_whataboutism_suggested'],
        '003_temp_password.sql' => ['admin_users', 'must_change_password'],
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

        foreach ($files as $file) {
            $name = basename($file);
            if (in_array($name, $alreadyApplied, true)) {
                continue;
            }

            if (isset(self::HISTORICAL_MARKERS[$name])
                && $this->columnExists(...self::HISTORICAL_MARKERS[$name])
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

        foreach ($this->splitStatements($sql) as $statement) {
            DB::statement($statement);
        }
    }

    /**
     * Divide un file .sql in singole statement, rispettando i literal di
     * stringa: un ';' o una riga '-- ...' dentro un valore stringa (es. il
     * system prompt seminato in 001_initial_schema.sql, che contiene sia
     * punti e virgola sia righe che iniziano per '--' come testo) NON deve
     * essere trattato come fine statement o commento. Un semplice split su
     * ';' o strip di righe '--' romperebbe quel file a metà stringa.
     *
     * @return string[]
     */
    private function splitStatements(string $sql): array
    {
        $statements = [];
        $current    = '';
        $len        = strlen($sql);
        $inString   = false;
        $quoteChar  = '';

        for ($i = 0; $i < $len; $i++) {
            $ch = $sql[$i];

            if ($inString) {
                $current .= $ch;
                if ($ch === '\\' && $i + 1 < $len) {
                    // Escape MySQL: il carattere successivo è letterale.
                    $current .= $sql[++$i];
                    continue;
                }
                if ($ch === $quoteChar) {
                    if (($sql[$i + 1] ?? '') === $quoteChar) {
                        // Quote raddoppiata ('' dentro '...') = quote letterale.
                        $current .= $sql[++$i];
                        continue;
                    }
                    $inString = false;
                }
                continue;
            }

            if ($ch === "'" || $ch === '"') {
                $inString  = true;
                $quoteChar = $ch;
                $current  .= $ch;
                continue;
            }

            if ($ch === '-' && ($sql[$i + 1] ?? '') === '-') {
                $nl = strpos($sql, "\n", $i);
                $i  = $nl === false ? $len : $nl; // il for() farà $i++ portandolo dopo il \n
                continue;
            }

            if ($ch === ';') {
                $statements[] = $current;
                $current      = '';
                continue;
            }

            $current .= $ch;
        }

        if (trim($current) !== '') {
            $statements[] = $current;
        }

        return array_values(array_filter(
            array_map('trim', $statements),
            fn(string $s): bool => $s !== '',
        ));
    }

    /**
     * Verifica esistenza colonna via information_schema invece di
     * "SHOW COLUMNS ... LIKE ?": i comandi SHOW non ammettono in modo
     * affidabile un placeholder bindato in una prepared statement su tutte
     * le versioni di MariaDB/MySQL, information_schema sì.
     */
    private function columnExists(string $table, string $column): bool
    {
        return !empty(DB::select(
            'SELECT 1 FROM information_schema.columns WHERE table_schema = DATABASE() AND table_name = ? AND column_name = ? LIMIT 1',
            [$table, $column],
        ));
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
