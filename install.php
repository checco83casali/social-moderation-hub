#!/usr/bin/env php
<?php
/**
 * Moderation Hub — Installation Script
 * 
 * Run once after deploying:
 *   php install.php
 * 
 * MIT License
 */

declare(strict_types=1);

define('MH_VERSION', '1.0.0');

echo <<<BANNER

╔═══════════════════════════════════════════════╗
║     Moderation Hub  v1.0.0  — Installer       ║
║     MIT License                               ║
╚═══════════════════════════════════════════════╝

BANNER;

// ── Step 1: Check PHP version ──────────────────────────────────────
step('Checking PHP version');
if (PHP_MAJOR_VERSION < 8 || (PHP_MAJOR_VERSION === 8 && PHP_MINOR_VERSION < 1)) {
    fail('PHP 8.1 or higher is required. Current: ' . PHP_VERSION);
}
ok('PHP ' . PHP_VERSION);

// ── Step 2: Check extensions ───────────────────────────────────────
step('Checking required PHP extensions');
$required = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'curl'];
$missing  = [];
foreach ($required as $ext) {
    if (!extension_loaded($ext)) {
        $missing[] = $ext;
    }
}
if ($missing) {
    fail('Missing extensions: ' . implode(', ', $missing));
}
ok('All extensions present');

// ── Step 3: Check Composer ─────────────────────────────────────────
step('Checking Composer dependencies');
if (!file_exists(__DIR__ . '/vendor/autoload.php')) {
    warn('vendor/ not found. Running: composer install');
    passthru('composer install --no-dev --optimize-autoloader', $code);
    if ($code !== 0) {
        fail('composer install failed. Fix errors above and re-run.');
    }
}
ok('Composer dependencies ready');

// ── Step 4: .env setup ────────────────────────────────────────────
step('Checking environment configuration');
if (!file_exists(__DIR__ . '/.env')) {
    if (!file_exists(__DIR__ . '/.env.example')) {
        fail('.env.example not found.');
    }
    copy(__DIR__ . '/.env.example', __DIR__ . '/.env');
    warn('.env created from .env.example — PLEASE EDIT IT BEFORE CONTINUING');
    echo "\n  Open .env and fill in:\n";
    echo "  - DB_HOST, DB_DATABASE, DB_USERNAME, DB_PASSWORD\n";
    echo "  - ANTHROPIC_API_KEY\n";
    echo "  - META_APP_ID, META_APP_SECRET\n";
    echo "  - OAuth credentials (Google/Meta/Microsoft)\n";
    echo "  - APP_URL and APP_SECRET\n\n";

    if (!confirm('Have you filled in .env?')) {
        echo "  Run this installer again when ready.\n\n";
        exit(0);
    }
}
ok('.env present');

// Load .env
$env = parse_ini_file(__DIR__ . '/.env');
foreach ($env as $k => $v) {
    $_ENV[$k] = $v;
    putenv("{$k}={$v}");
}

// Validate critical keys
$criticalKeys = ['DB_HOST', 'DB_DATABASE', 'DB_USERNAME', 'DB_PASSWORD', 'ANTHROPIC_API_KEY', 'APP_SECRET', 'APP_URL'];
foreach ($criticalKeys as $key) {
    if (empty($_ENV[$key]) || str_contains($_ENV[$key] ?? '', 'your_')) {
        fail("'{$key}' is not configured in .env");
    }
}
ok('Environment validated');

// ── Step 5: Database connection ────────────────────────────────────
step('Testing database connection');
try {
    $pdo = new PDO(
        "mysql:host={$_ENV['DB_HOST']};port=" . ($_ENV['DB_PORT'] ?? 3306) . ";charset=utf8mb4",
        $_ENV['DB_USERNAME'],
        $_ENV['DB_PASSWORD'],
        [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
    );
} catch (PDOException $e) {
    fail('Cannot connect to MySQL: ' . $e->getMessage());
}
ok("Connected to MySQL on {$_ENV['DB_HOST']}");

// ── Step 6: Create / migrate database ─────────────────────────────
step("Setting up database '{$_ENV['DB_DATABASE']}'");

// Create DB if not exists
$pdo->exec("CREATE DATABASE IF NOT EXISTS `{$_ENV['DB_DATABASE']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
$pdo->exec("USE `{$_ENV['DB_DATABASE']}`");

// Check if already migrated
$tables = $pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchAll();
if (!empty($tables)) {
    warn('Tables already exist — skipping migration (run manually if schema changed)');
} else {
    $sql = file_get_contents(__DIR__ . '/database/migrations/001_initial_schema.sql');
    // Split on statement delimiter and execute each
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        if (!empty($statement)) {
            try {
                $pdo->exec($statement);
            } catch (PDOException $e) {
                // Ignore "already exists" errors from IF NOT EXISTS
                if ($e->getCode() !== '42S01') {
                    warn("SQL warning: " . $e->getMessage());
                }
            }
        }
    }
    ok('Database schema created');
}

// ── Step 7: Generate APP_SECRET if default ─────────────────────────
step('Checking APP_SECRET');
if ($_ENV['APP_SECRET'] === 'change_this_to_a_random_secret_64chars') {
    $secret  = bin2hex(random_bytes(32));
    $content = file_get_contents(__DIR__ . '/.env');
    $content = preg_replace('/^APP_SECRET=.*/m', "APP_SECRET={$secret}", $content);
    file_put_contents(__DIR__ . '/.env', $content);
    ok("Generated new APP_SECRET (saved to .env)");
} else {
    ok('APP_SECRET is set');
}

// ── Step 8: Logs directory ────────────────────────────────────────
step('Checking logs directory');
$logsDir = __DIR__ . '/logs';
if (!is_dir($logsDir)) {
    mkdir($logsDir, 0755, true);
}
if (!is_writable($logsDir)) {
    warn("Logs directory not writable. Run: chmod 755 {$logsDir}");
} else {
    ok('Logs directory ready');
}

// ── Done ──────────────────────────────────────────────────────────
echo "\n" . str_repeat('─', 50) . "\n";
echo "  ✅  Installation complete!\n\n";
echo "  Next steps:\n";
echo "  1. Point your web server document root → public/\n";
echo "  2. Set up Meta webhook at: {$_ENV['APP_URL']}/webhook/meta\n";
echo "  3. Open {$_ENV['APP_URL']}/auth/google to log in for the first time\n";
echo "     (first login becomes admin automatically)\n";
echo "  4. Connect a Facebook Page from the dashboard\n\n";

// ── Helpers ───────────────────────────────────────────────────────

function step(string $label): void
{
    echo "  › {$label}… ";
}

function ok(string $msg): void
{
    echo "✓ {$msg}\n";
}

function warn(string $msg): void
{
    echo "\n  ⚠  {$msg}\n  ";
}

function fail(string $msg): never
{
    echo "\n  ✖  ERROR: {$msg}\n\n";
    exit(1);
}

function confirm(string $question): bool
{
    echo "\n  ? {$question} [y/N] ";
    $answer = strtolower(trim(fgets(STDIN)));
    return $answer === 'y' || $answer === 'yes';
}
