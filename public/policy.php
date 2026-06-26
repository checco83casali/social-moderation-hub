<?php
// public/policy.php
// Pagina pubblica di trasparenza — regole di moderazione attive.
// Accessibile senza autenticazione a GET /public/policy
// Se $jsonMode = true (impostato dalla route /public/policy.json), restituisce JSON puro.

declare(strict_types=1);

// Bootstrap — gestisce sia il caso incluso da Slim che standalone
if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
    require __DIR__ . '/../vendor/autoload.php';
}
if (empty($_ENV['DB_HOST'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

$DB = \Illuminate\Database\Capsule\Manager::class;

// Se la Capsule non è già attiva (caso standalone), la avvia
try {
    $DB::table('policies')->limit(1)->get();
} catch (\Throwable) {
    $capsule = new \Illuminate\Database\Capsule\Manager;
    $capsule->addConnection([
        'driver'    => 'mysql',
        'host'      => $_ENV['DB_HOST'],
        'port'      => $_ENV['DB_PORT'] ?? 3306,
        'database'  => $_ENV['DB_DATABASE'],
        'username'  => $_ENV['DB_USERNAME'],
        'password'  => $_ENV['DB_PASSWORD'],
        'charset'   => 'utf8mb4',
        'collation' => 'utf8mb4_unicode_ci',
    ]);
    $capsule->setAsGlobal();
    $capsule->bootEloquent();
}

// ── Dati dal DB ───────────────────────────────────────────────────
$policy  = $DB::table('policies')->where('is_active', 1)->first();
$appUrl  = rtrim($DB::table('app_settings')->where('key', 'app_url')->value('value') ?? '', '/');
$orgName = $DB::table('app_settings')->where('key', 'privacy_org_name')->value('value') ?? '';

// ── JSON mode ─────────────────────────────────────────────────────
if (!empty($jsonMode)) {
    if (!$policy) {
        echo json_encode(['error' => 'No active policy']);
        return;
    }
    echo json_encode([
        'policy' => [
            'name'         => $policy->name,
            'description'  => $policy->description,
            'version'      => $policy->version,
            'activated_at' => $policy->updated_at,
        ],
        'moderation_rules' => $policy->moderation_prompt,
        'pipeline' => [
            'stage_1' => 'Claude Haiku — fast initial analysis',
            'stage_2' => 'Claude Sonnet — deep analysis (when Haiku confidence is below threshold)',
            'stage_3' => 'Human moderator — review (when both AI stages are uncertain)',
        ],
    ], JSON_UNESCAPED_UNICODE | JSON_PRETTY_PRINT);
    return;
}

// ── HTML mode ─────────────────────────────────────────────────────
if (!$policy) {
    echo '<p style="font-family:sans-serif;padding:2rem;color:#666">Nessuna policy attiva.</p>';
    return;
}

$promptRaw     = $policy->moderation_prompt;
$policyName    = htmlspecialchars($policy->name        ?? '', ENT_QUOTES, 'UTF-8');
$policyDesc    = htmlspecialchars($policy->description ?? '', ENT_QUOTES, 'UTF-8');
$policyVersion = (int) ($policy->version ?? 1);
$policyDate    = htmlspecialchars($policy->updated_at  ?? '', ENT_QUOTES, 'UTF-8');
$orgNameEsc    = htmlspecialchars($orgName, ENT_QUOTES, 'UTF-8');
$privacyUrl    = $appUrl . '/privacy';
$jsonUrl       = $appUrl . '/public/policy.json';

// ── Converte il testo del prompt in HTML leggibile ────────────────
function promptToHtml(string $raw): string
{
    $lines    = explode("\n", $raw);
    $out      = '';
    $inBlock  = false;

    foreach ($lines as $line) {
        $esc = htmlspecialchars($line, ENT_QUOTES, 'UTF-8');

        // Separatori ════ → chiude il blocco corrente, non viene mostrato
        if (str_starts_with(trim($line), '════')) {
            if ($inBlock) { $out .= '</p>'; $inBlock = false; }
            continue;
        }

        // Riga vuota → interruzione di paragrafo
        if (trim($line) === '') {
            if ($inBlock) { $out .= '</p>'; $inBlock = false; }
            continue;
        }

        // Titoli di sezione: tutto maiuscolo (es. "BASIC VIOLATIONS:")
        if (preg_match('/^[A-Z][A-Z\s&\(\)\/\-–]+:?\s*$/', trim($line))) {
            if ($inBlock) { $out .= '</p>'; $inBlock = false; }
            $out .= '<h3 class="ps">' . $esc . '</h3>';
            continue;
        }

        // Riga tabella routing (contiene |)
        if (str_contains($line, '|') && !str_starts_with(trim($line), '-')) {
            if (!$inBlock) { $out .= '<p class="pp">'; $inBlock = true; }
            $out .= '<code class="ptr">' . $esc . '</code><br>';
            continue;
        }

        // Bullet point
        if (str_starts_with(trim($line), '-')) {
            if (!$inBlock) { $out .= '<p class="pp">'; $inBlock = true; }
            $out .= '<span class="pb">' . $esc . '</span><br>';
            continue;
        }

        // Testo normale
        if (!$inBlock) { $out .= '<p class="pp">'; $inBlock = true; }
        $out .= $esc . '<br>';
    }

    if ($inBlock) $out .= '</p>';
    return $out;
}

$promptHtml = promptToHtml($promptRaw);

?><!DOCTYPE html>
<html lang="it">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Regole di Moderazione – Social Moderation Hub</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <link rel="preconnect" href="https://fonts.googleapis.com">
  <link href="https://fonts.googleapis.com/css2?family=DM+Sans:wght@300;400;500;600&family=DM+Mono:wght@400;500&display=swap" rel="stylesheet">
  <style>
    :root {
      --bg:#0e0f11; --bg-card:#16181c; --bg-hover:#1e2126;
      --border:rgba(255,255,255,.07); --border-hi:rgba(255,255,255,.14);
      --text:#e8eaf0; --muted:#7a7f8e; --accent:#4f8ef7;
      --success:#3ecf8e; --warn:#f7b244;
      --font:'DM Sans',sans-serif; --mono:'DM Mono',monospace;
      --radius:10px;
    }
    *, *::before, *::after { box-sizing:border-box; margin:0; padding:0; }
    body { font-family:var(--font); background:var(--bg); color:var(--text);
           min-height:100vh; font-size:14px; line-height:1.6; }
    .page { max-width:820px; margin:0 auto; padding:2.5rem 1.5rem 5rem; }

    /* Header */
    .hdr { display:flex; align-items:center; gap:14px; margin-bottom:2.5rem;
            padding-bottom:1.5rem; border-bottom:1px solid var(--border); }
    .logo-icon { width:38px; height:38px; background:var(--accent); border-radius:10px;
                  display:flex; align-items:center; justify-content:center; flex-shrink:0; }
    .logo-icon svg { width:20px; height:20px; }
    .hdr-title { font-size:1.3rem; font-weight:600; letter-spacing:-.3px; }
    .hdr-sub   { font-size:12px; color:var(--muted); margin-top:3px; }

    /* Badges */
    .meta  { display:flex; flex-wrap:wrap; gap:10px; margin-bottom:2rem; }
    .badge { display:inline-flex; align-items:center; gap:5px; font-size:12px;
              padding:5px 12px; border-radius:20px; font-weight:500; }
    .b-ok  { background:rgba(62,207,142,.12); color:var(--success); border:1px solid rgba(62,207,142,.25); }
    .b-dim { background:var(--bg-hover); color:var(--muted); border:1px solid var(--border-hi); }

    /* Info */
    .info { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
             padding:1rem 1.25rem; margin-bottom:2rem; font-size:13px; color:var(--muted); line-height:1.7; }
    .info strong { color:var(--text); }

    /* Pipeline */
    .pipeline { display:grid; grid-template-columns:repeat(3,1fr); gap:12px; margin-bottom:2rem; }
    .ps-step  { background:var(--bg-card); border:1px solid var(--border); border-radius:var(--radius);
                 padding:1rem; text-align:center; }
    .ps-num   { font-size:11px; font-weight:600; color:var(--accent); letter-spacing:.5px;
                 text-transform:uppercase; margin-bottom:6px; }
    .ps-name  { font-size:13px; font-weight:500; margin-bottom:4px; }
    .ps-desc  { font-size:11.5px; color:var(--muted); line-height:1.5; }
    @media(max-width:580px){ .pipeline { grid-template-columns:1fr; } }

    /* Panel */
    .panel     { background:var(--bg-card); border:1px solid var(--border);
                  border-radius:var(--radius); overflow:hidden; margin-bottom:2rem; }
    .p-head    { padding:.9rem 1.25rem; border-bottom:1px solid var(--border);
                  display:flex; align-items:center; justify-content:space-between; gap:8px; flex-wrap:wrap; }
    .p-title   { font-size:13px; font-weight:600; }
    .p-note    { font-size:11.5px; color:var(--muted); }
    .p-body    { padding:1.25rem; }

    /* Prompt rendering */
    .ps  { font-size:11px; font-weight:600; color:var(--accent); letter-spacing:.6px;
            text-transform:uppercase; margin:1.6rem 0 .6rem; }
    .ps:first-child { margin-top:0; }
    .pp  { font-size:13.5px; color:#c8ccd8; line-height:1.8; margin-bottom:.25rem; }
    .pb  { display:block; font-size:13px; color:#b0b5c4; padding-left:1.2rem;
            position:relative; line-height:1.65; }
    .pb::before { content:'–'; position:absolute; left:0; color:var(--muted); }
    .ptr { display:block; font-family:var(--mono); font-size:11.5px; color:#7a8494;
            padding:1px 0; white-space:pre; overflow-x:auto; }

    /* Warning box */
    .warn-box { background:rgba(247,178,68,.07); border:1px solid rgba(247,178,68,.2);
                 border-radius:var(--radius); padding:1rem 1.25rem; font-size:13px;
                 color:#b89060; line-height:1.7; margin-bottom:2rem; }

    /* Footer */
    .foot { font-size:12px; color:var(--muted); padding-top:1.5rem;
             border-top:1px solid var(--border); display:flex;
             justify-content:space-between; flex-wrap:wrap; gap:8px; align-items:center; }
    .foot a { color:var(--accent); text-decoration:none; }
    .foot a:hover { text-decoration:underline; }
  </style>
</head>
<body>
<div class="page">

  <div class="hdr">
    <div class="logo-icon">
      <svg viewBox="0 0 24 24" fill="none" stroke="#fff" stroke-width="2" stroke-linecap="round">
        <path d="M12 22s8-4 8-10V5l-8-3-8 3v7c0 6 8 10 8 10z"/>
      </svg>
    </div>
    <div>
      <div class="hdr-title">Regole di Moderazione</div>
      <div class="hdr-sub">Social Moderation Hub — Pagina pubblica di trasparenza</div>
    </div>
  </div>

  <div class="meta">
    <span class="badge b-ok">● Attiva</span>
    <span class="badge b-dim">Policy: <?= $policyName ?> v<?= $policyVersion ?></span>
    <span class="badge b-dim">Aggiornata: <?= $policyDate ?></span>
  </div>

  <div class="info">
    <strong>Perché questa pagina?</strong><br>
    In conformità all'art. 22 del GDPR (decisioni automatizzate) e al principio di
    trasparenza (art. 5.1.a), le regole con cui il sistema AI valuta i commenti sono
    pubbliche e consultabili senza autenticazione.
    <?php if ($policyDesc): ?><br><br><?= $policyDesc ?><?php endif; ?>
  </div>

  <div class="pipeline">
    <div class="ps-step">
      <div class="ps-num">Livello 1</div>
      <div class="ps-name">Claude Haiku</div>
      <div class="ps-desc">Analisi rapida. Se sicuro, decide in autonomia.</div>
    </div>
    <div class="ps-step">
      <div class="ps-num">Livello 2</div>
      <div class="ps-name">Claude Sonnet</div>
      <div class="ps-desc">Analisi approfondita. Attivata quando il Livello 1 è incerto.</div>
    </div>
    <div class="ps-step">
      <div class="ps-num">Livello 3</div>
      <div class="ps-name">Revisione umana</div>
      <div class="ps-desc">Un moderatore decide. Sempre attivo per casi ambigui o segnalabili.</div>
    </div>
  </div>

  <div class="panel">
    <div class="p-head">
      <div class="p-title">📋 Regole operative</div>
      <div class="p-note">Aggiornate in tempo reale dall'operatore</div>
    </div>
    <div class="p-body">
      <?= $promptHtml ?>
    </div>
  </div>

  <div class="warn-box">
    ⚠ <strong>Blocco tecnico escluso:</strong> il contratto di output JSON (formato della risposta AI,
    semantica delle decisioni, regole di lingua e lunghezza) è hardcoded nel codice applicativo
    e non è mostrato qui — è irrilevante per gli utenti e la sua pubblicazione potrebbe
    facilitare l'aggiramento dei filtri di moderazione.
  </div>

  <div class="foot">
    <span>
      <?= $orgNameEsc ?>
      <?php if ($privacyUrl !== '/privacy'): ?>
        — <a href="<?= htmlspecialchars($privacyUrl, ENT_QUOTES, 'UTF-8') ?>">Informativa Privacy</a>
      <?php endif; ?>
    </span>
    <a href="<?= htmlspecialchars($jsonUrl, ENT_QUOTES, 'UTF-8') ?>">Versione JSON</a>
  </div>

</div>
</body>
</html>