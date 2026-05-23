<?php
/**
 * Web installer for Social Moderation Hub.
 *
 * Served at /install.php. Guides through configuration, writes .env, tests and
 * migrates the database, generates secrets. Composer (`composer install`) must
 * still be run from the CLI — this installer only checks that vendor/ exists.
 *
 * SECURITY: this file writes .env and runs SQL. It refuses to run once a lock
 * file (.installed) is present, and it must be DELETED after setup. Keep it on
 * an internal-only path (see docs/deployment-security.md).
 */
declare(strict_types=1);

error_reporting(E_ALL & ~E_DEPRECATED & ~E_NOTICE);

$ROOT      = dirname(__DIR__);
$ENV_PATH  = $ROOT . '/.env';
$EXAMPLE   = $ROOT . '/.env.example';
$LOCK_PATH = $ROOT . '/.installed';
$MIGRATION = $ROOT . '/database/migrations/001_initial_schema.sql';

// ── Helpers ─────────────────────────────────────────────────────────────────
function h(?string $s): string { return htmlspecialchars((string) $s, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8'); }

function envQuote(string $v): string {
    if ($v === '') return '';
    if (preg_match('/[\s#"\'\\\\$]/', $v)) {
        return '"' . str_replace(['\\', '"'], ['\\\\', '\\"'], $v) . '"';
    }
    return $v;
}

/** Render the final .env from the example template, overriding the given keys. */
function buildEnv(string $examplePath, array $values): string {
    $content = is_file($examplePath) ? file_get_contents($examplePath) : '';
    foreach ($values as $k => $v) {
        $line = $k . '=' . envQuote((string) $v);
        $pat  = '/^' . preg_quote($k, '/') . '=.*/m';
        if (preg_match($pat, $content)) {
            $content = preg_replace($pat, addcslashes($line, '\\$'), $content, 1);
        } else {
            $content = rtrim($content, "\n") . "\n" . $line . "\n";
        }
    }
    return $content;
}

function readExistingEnv(string $path): array {
    if (!is_file($path)) return [];
    $vals = @parse_ini_file($path, false, INI_SCANNER_RAW);
    return is_array($vals) ? $vals : [];
}

// ── State ─────────────────────────────────────────────────────────────────
$alreadyInstalled = is_file($LOCK_PATH);

// Pre-flight environment checks
$exts    = ['pdo', 'pdo_mysql', 'mbstring', 'json', 'openssl', 'curl'];
$missing = array_values(array_filter($exts, fn($e) => !extension_loaded($e)));
$phpOk      = PHP_VERSION_ID >= 80100;
$vendorOk   = is_file($ROOT . '/vendor/autoload.php');
$envWritable = is_writable($ROOT) || (is_file($ENV_PATH) && is_writable($ENV_PATH));
$migrationOk = is_file($MIGRATION);
$preflightOk = $phpOk && $missing === [] && $vendorOk && $envWritable && $migrationOk;

$errors  = [];
$success = false;
$verifyToken = '';
$appUrl      = '';

$existing = readExistingEnv($ENV_PATH);
$old = fn(string $k, string $d = '') => h($_POST[$k] ?? $existing[$k] ?? $d);

// ── Handle submit ───────────────────────────────────────────────────────────
if (($_SERVER['REQUEST_METHOD'] ?? 'GET') === 'POST' && !$alreadyInstalled && $preflightOk) {
    $f = fn(string $k, string $d = '') => trim((string) ($_POST[$k] ?? $d));

    $cfg = [
        'APP_NAME'                   => $f('APP_NAME', 'Moderation Hub'),
        'SITE_NAME'                  => $f('SITE_NAME', $f('APP_NAME', 'Moderation Hub')),
        'APP_ENV'                    => 'production',
        'APP_URL'                    => rtrim($f('APP_URL'), '/'),
        'APP_TIMEZONE'               => $f('APP_TIMEZONE', 'Europe/Rome'),
        'DB_HOST'                    => $f('DB_HOST', 'localhost'),
        'DB_PORT'                    => $f('DB_PORT', '3306'),
        'DB_DATABASE'                => $f('DB_DATABASE'),
        'DB_USERNAME'                => $f('DB_USERNAME'),
        'DB_PASSWORD'                => $f('DB_PASSWORD'),
        'ANTHROPIC_API_KEY'          => $f('ANTHROPIC_API_KEY'),
        'META_APP_ID'                => $f('META_APP_ID'),
        'META_APP_SECRET'            => $f('META_APP_SECRET'),
        'OAUTH_GOOGLE_CLIENT_ID'     => $f('OAUTH_GOOGLE_CLIENT_ID'),
        'OAUTH_GOOGLE_CLIENT_SECRET' => $f('OAUTH_GOOGLE_CLIENT_SECRET'),
        'OAUTH_ALLOWED_EMAIL_DOMAINS'=> $f('OAUTH_ALLOWED_EMAIL_DOMAINS'),
        'INTERNAL_IP_ALLOWLIST'      => $f('INTERNAL_IP_ALLOWLIST'),
    ];

    // Required fields
    $required = [
        'APP_URL' => 'URL applicazione', 'DB_HOST' => 'DB host', 'DB_DATABASE' => 'Nome database',
        'DB_USERNAME' => 'DB utente', 'ANTHROPIC_API_KEY' => 'Anthropic API key',
        'META_APP_ID' => 'Meta App ID', 'META_APP_SECRET' => 'Meta App Secret',
    ];
    foreach ($required as $k => $label) {
        if ($cfg[$k] === '') $errors[] = "Campo obbligatorio mancante: {$label}.";
    }
    if (!preg_match('/^https?:\/\//', $cfg['APP_URL'])) {
        $errors[] = "L'URL applicazione deve iniziare con http:// o https://.";
    }
    if ($cfg['DB_DATABASE'] !== '' && !preg_match('/^[A-Za-z0-9_]+$/', $cfg['DB_DATABASE'])) {
        $errors[] = 'Il nome database può contenere solo lettere, numeri e underscore.';
    }
    if ($cfg['OAUTH_GOOGLE_CLIENT_ID'] === '' && $cfg['OAUTH_GOOGLE_CLIENT_SECRET'] === '') {
        $errors[] = 'Configura almeno il provider Google (o aggiungi un altro provider nel .env dopo).';
    }

    // DB connection + migration
    if (!$errors) {
        try {
            $pdo = new PDO(
                "mysql:host={$cfg['DB_HOST']};port={$cfg['DB_PORT']};charset=utf8mb4",
                $cfg['DB_USERNAME'], $cfg['DB_PASSWORD'],
                [PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION]
            );
            $pdo->exec("CREATE DATABASE IF NOT EXISTS `{$cfg['DB_DATABASE']}` CHARACTER SET utf8mb4 COLLATE utf8mb4_unicode_ci");
            $pdo->exec("USE `{$cfg['DB_DATABASE']}`");

            $hasTables = $pdo->query("SHOW TABLES LIKE 'admin_users'")->fetchAll();
            if (!$hasTables) {
                $sql = file_get_contents($MIGRATION);
                foreach (array_filter(array_map('trim', explode(';', $sql))) as $stmt) {
                    if ($stmt === '') continue;
                    try { $pdo->exec($stmt); }
                    catch (PDOException $e) { if ($e->getCode() !== '42S01') throw $e; }
                }
            }
        } catch (PDOException $e) {
            $errors[] = 'Errore database: ' . $e->getMessage();
        }
    }

    // Generate secrets + write .env + lock
    if (!$errors) {
        $verifyToken = bin2hex(random_bytes(24));
        $cfg['APP_SECRET']                = bin2hex(random_bytes(32));
        $cfg['META_WEBHOOK_VERIFY_TOKEN'] = $verifyToken;

        $envContent = buildEnv($EXAMPLE, $cfg);
        if (@file_put_contents($ENV_PATH, $envContent) === false) {
            $errors[] = 'Impossibile scrivere il file .env (permessi?). Contenuto non salvato.';
        } else {
            @chmod($ENV_PATH, 0640);
            if (!is_dir($ROOT . '/logs')) @mkdir($ROOT . '/logs', 0755, true);
            @file_put_contents($LOCK_PATH, date('c') . " installed via web installer\n");
            $appUrl  = $cfg['APP_URL'];
            $success = true;
        }
    }
}

// ── HTML ─────────────────────────────────────────────────────────────────
?>
<!DOCTYPE html>
<html lang="it">
<head>
<meta charset="UTF-8">
<meta name="viewport" content="width=device-width, initial-scale=1.0">
<title>Installazione · Moderation Hub</title>
<link rel="icon" type="image/svg+xml" href="/favicon.svg">
<style>
  :root{--bg:#0e0f11;--card:#16181c;--hover:#1e2126;--border:#262a30;--text:#e6e8eb;
    --muted:#8b919a;--accent:#4f8ef7;--accent-bg:rgba(79,142,247,.12);
    --ok:#3ecf8e;--ok-bg:rgba(62,207,142,.1);--err:#f75252;--err-bg:rgba(247,82,82,.1);
    --warn:#f7b244;--warn-bg:rgba(247,178,68,.12);}
  *{box-sizing:border-box;margin:0;padding:0}
  body{font-family:-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,system-ui,sans-serif;
    background:var(--bg);color:var(--text);line-height:1.5;padding:32px 16px;min-height:100vh}
  .wrap{max-width:680px;margin:0 auto}
  .brand{display:flex;align-items:center;gap:12px;margin-bottom:6px}
  .brand img{width:44px;height:44px;border-radius:11px}
  .brand h1{font-size:20px;font-weight:600;letter-spacing:-.3px}
  .sub{color:var(--muted);font-size:13px;margin-bottom:24px}
  .card{background:var(--card);border:1px solid var(--border);border-radius:14px;padding:22px 24px;margin-bottom:18px}
  h2{font-size:15px;font-weight:600;margin-bottom:14px;display:flex;align-items:center;gap:8px}
  label{display:block;font-size:12px;font-weight:600;color:var(--muted);margin:12px 0 5px;text-transform:uppercase;letter-spacing:.04em}
  .req::after{content:" *";color:var(--accent)}
  input{width:100%;padding:10px 12px;background:var(--bg);border:1px solid var(--border);border-radius:8px;
    color:var(--text);font-size:14px;font-family:inherit}
  input:focus{outline:none;border-color:var(--accent)}
  .hint{font-size:11.5px;color:var(--muted);margin-top:4px}
  .row{display:grid;grid-template-columns:2fr 1fr;gap:12px}
  .grid2{display:grid;grid-template-columns:1fr 1fr;gap:12px}
  .check{display:flex;align-items:center;gap:9px;font-size:13.5px;padding:5px 0}
  .dot{width:18px;height:18px;border-radius:50%;flex-shrink:0;display:flex;align-items:center;justify-content:center;font-size:11px;font-weight:700}
  .dot.ok{background:var(--ok-bg);color:var(--ok)} .dot.no{background:var(--err-bg);color:var(--err)}
  .btn{width:100%;padding:13px;background:var(--accent);color:#fff;border:none;border-radius:9px;
    font-size:15px;font-weight:600;cursor:pointer;margin-top:18px}
  .btn:hover{filter:brightness(1.07)} .btn:disabled{opacity:.45;cursor:not-allowed}
  .alert{padding:12px 14px;border-radius:9px;font-size:13px;margin-bottom:14px}
  .alert.err{background:var(--err-bg);border:1px solid rgba(247,82,82,.25);color:#ffb4b4}
  .alert.warn{background:var(--warn-bg);border:1px solid rgba(247,178,68,.25);color:#f7d9a0}
  .alert.ok{background:var(--ok-bg);border:1px solid rgba(62,207,142,.25);color:#9af0c8}
  .alert ul{margin:6px 0 0 18px} .alert b{color:#fff}
  code{font-family:'DM Mono',ui-monospace,monospace;background:var(--bg);border:1px solid var(--border);
    border-radius:6px;padding:2px 7px;font-size:12.5px;word-break:break-all;color:var(--accent)}
  .token{display:block;margin-top:6px;padding:10px 12px}
  ol{margin:8px 0 0 20px;font-size:13.5px} ol li{margin:5px 0}
  .muted{color:var(--muted);font-size:12.5px}
</style>
</head>
<body>
<div class="wrap">
  <div class="brand">
    <img src="/favicon.svg" alt="">
    <h1>Moderation Hub</h1>
  </div>
  <div class="sub">Installazione guidata</div>

<?php if ($alreadyInstalled): ?>

  <div class="card">
    <div class="alert warn"><b>Installazione già completata.</b><br>
      L'installer è bloccato dal file di lock <code>.installed</code>.</div>
    <p class="muted">Per motivi di sicurezza, <b>elimina ora</b> <code>public/install.php</code>.
    Per rieseguire l'installazione (azzerando la configurazione) rimuovi sia
    <code>install.php</code> sia il file <code>.installed</code> nella root del progetto.</p>
  </div>

<?php elseif ($success): ?>

  <div class="card">
    <div class="alert ok"><b>✓ Installazione completata!</b> Configurazione salvata in <code>.env</code> e database pronto.</div>

    <h2>Token di verifica webhook</h2>
    <p class="muted">Inseriscilo come <b>Verify Token</b> nella configurazione del webhook su Meta:</p>
    <code class="token"><?= h($verifyToken) ?></code>

    <h2 style="margin-top:20px">Prossimi passi</h2>
    <ol>
      <li>Configura il webhook Meta su <code><?= h($appUrl) ?>/webhook/meta</code> con il token qui sopra.</li>
      <li>Apri <code><?= h($appUrl) ?>/auth/google</code> per il primo accesso (il primo utente diventa admin).</li>
      <li>Collega una pagina Facebook dal dashboard.</li>
      <li>Applica l'hardening di rete (vedi <code>docs/deployment-security.md</code>).</li>
    </ol>

    <div class="alert err" style="margin-top:18px"><b>⚠ Importante:</b> elimina subito
      <code>public/install.php</code> dal server — è un file ad alto rischio se lasciato accessibile.</div>
  </div>

<?php else: ?>

  <div class="card">
    <h2>Requisiti di sistema</h2>
    <div class="check"><span class="dot <?= $phpOk?'ok':'no' ?>"><?= $phpOk?'✓':'✕' ?></span> PHP ≥ 8.1 <span class="muted">(<?= h(PHP_VERSION) ?>)</span></div>
    <div class="check"><span class="dot <?= $missing===[]?'ok':'no' ?>"><?= $missing===[]?'✓':'✕' ?></span> Estensioni richieste <?= $missing? '<span class="muted">— mancano: '.h(implode(', ',$missing)).'</span>':'' ?></div>
    <div class="check"><span class="dot <?= $vendorOk?'ok':'no' ?>"><?= $vendorOk?'✓':'✕' ?></span> Dipendenze Composer <?= $vendorOk?'':'<span class="muted">— esegui da CLI: <code>composer install --no-dev</code></span>' ?></div>
    <div class="check"><span class="dot <?= $envWritable?'ok':'no' ?>"><?= $envWritable?'✓':'✕' ?></span> Scrittura <code>.env</code></div>
    <div class="check"><span class="dot <?= $migrationOk?'ok':'no' ?>"><?= $migrationOk?'✓':'✕' ?></span> File di migrazione presente</div>
  </div>

  <?php if (!$preflightOk): ?>
    <div class="alert warn">Risolvi i requisiti mancanti qui sopra, poi ricarica la pagina.</div>
  <?php endif; ?>

  <?php if ($errors): ?>
    <div class="alert err"><b>Correggi gli errori:</b><ul><?php foreach ($errors as $e) echo '<li>'.h($e).'</li>'; ?></ul></div>
  <?php endif; ?>

  <form method="POST">
    <div class="card">
      <h2>Applicazione</h2>
      <label class="req">URL pubblico (APP_URL)</label>
      <input name="APP_URL" value="<?= $old('APP_URL') ?>" placeholder="https://mod.tuodominio.sm" required>
      <div class="hint">Origine del dashboard/API. Usato anche per il CORS.</div>
      <div class="grid2">
        <div><label>Nome</label><input name="APP_NAME" value="<?= $old('APP_NAME','Moderation Hub') ?>"></div>
        <div><label>Timezone</label><input name="APP_TIMEZONE" value="<?= $old('APP_TIMEZONE','Europe/Rome') ?>"></div>
      </div>
    </div>

    <div class="card">
      <h2>Database MySQL</h2>
      <div class="row">
        <div><label class="req">Host</label><input name="DB_HOST" value="<?= $old('DB_HOST','localhost') ?>" required></div>
        <div><label>Porta</label><input name="DB_PORT" value="<?= $old('DB_PORT','3306') ?>"></div>
      </div>
      <label class="req">Nome database</label>
      <input name="DB_DATABASE" value="<?= $old('DB_DATABASE','moderation_hub') ?>" required>
      <div class="hint">Verrà creato se non esiste. Solo lettere, numeri, underscore.</div>
      <div class="grid2">
        <div><label class="req">Utente</label><input name="DB_USERNAME" value="<?= $old('DB_USERNAME') ?>" required></div>
        <div><label>Password</label><input type="password" name="DB_PASSWORD" value="<?= $old('DB_PASSWORD') ?>"></div>
      </div>
    </div>

    <div class="card">
      <h2>Claude AI &amp; Meta</h2>
      <label class="req">Anthropic API key</label>
      <input name="ANTHROPIC_API_KEY" value="<?= $old('ANTHROPIC_API_KEY') ?>" placeholder="sk-ant-..." required>
      <div class="grid2">
        <div><label class="req">Meta App ID</label><input name="META_APP_ID" value="<?= $old('META_APP_ID') ?>" required></div>
        <div><label class="req">Meta App Secret</label><input type="password" name="META_APP_SECRET" value="<?= $old('META_APP_SECRET') ?>" required></div>
      </div>
      <div class="hint">Il token di verifica webhook viene generato automaticamente a fine installazione.</div>
    </div>

    <div class="card">
      <h2>Accesso moderatori (OAuth Google)</h2>
      <div class="grid2">
        <div><label>Google Client ID</label><input name="OAUTH_GOOGLE_CLIENT_ID" value="<?= $old('OAUTH_GOOGLE_CLIENT_ID') ?>"></div>
        <div><label>Google Client Secret</label><input type="password" name="OAUTH_GOOGLE_CLIENT_SECRET" value="<?= $old('OAUTH_GOOGLE_CLIENT_SECRET') ?>"></div>
      </div>
      <label>Domini email ammessi</label>
      <input name="OAUTH_ALLOWED_EMAIL_DOMAINS" value="<?= $old('OAUTH_ALLOWED_EMAIL_DOMAINS') ?>" placeholder="rtv.sm,datatrade.sm">
      <div class="hint">Consigliato: limita il login alla tua organizzazione. Vuoto = qualsiasi email.</div>
    </div>

    <div class="card">
      <h2>Sicurezza di rete <span class="muted" style="font-weight:400;text-transform:none;letter-spacing:0">(opzionale)</span></h2>
      <label>IP/CIDR interni ammessi (INTERNAL_IP_ALLOWLIST)</label>
      <input name="INTERNAL_IP_ALLOWLIST" value="<?= $old('INTERNAL_IP_ALLOWLIST') ?>" placeholder="10.0.0.0/8,192.168.0.0/16,127.0.0.1">
      <div class="hint">Limita tutto tranne il webhook agli IP interni. La protezione primaria resta il firewall — vedi docs/deployment-security.md.</div>
    </div>

    <button class="btn" type="submit" <?= $preflightOk?'':'disabled' ?>>Installa</button>
    <p class="muted" style="text-align:center;margin-top:10px"><code>APP_SECRET</code> e il verify token vengono generati e salvati automaticamente.</p>
  </form>

<?php endif; ?>
</div>
</body>
</html>
