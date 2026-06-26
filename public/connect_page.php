<?php
require __DIR__ . '/../vendor/autoload.php';
$dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
$dotenv->load();

$appId      = $_ENV['META_APP_ID'];
$appSecret  = $_ENV['META_APP_SECRET'];
$appUrl      = rtrim($_ENV['APP_URL'] ?? '', '/');
$redirectUri = $appUrl . '/connect_page.php';
$apiBase     = $appUrl . '/api';

// ── Step 3: connessione pagina via POST ────────────────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['page_id'])) {
    $jwtToken = $_POST['jwt_token'] ?? '';
    $data = [
        'page_id'           => $_POST['page_id'],
        'page_name'         => $_POST['page_name'],
        'page_access_token' => $_POST['page_access_token'],
        'owner_fb_id'       => $_POST['owner_fb_id'] ?? null,
    ];
    $ch = curl_init($apiBase . '/pages/connect');
    curl_setopt_array($ch, [
        CURLOPT_POST           => true,
        CURLOPT_POSTFIELDS     => json_encode($data),
        CURLOPT_HTTPHEADER     => [
            'Content-Type: application/json',
            'Authorization: Bearer ' . $jwtToken,
        ],
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_SSL_VERIFYPEER => true,
    ]);
    $result = json_decode(curl_exec($ch), true);
    curl_close($ch);

    $ok = isset($result['id']);
    echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
    <style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px}
    .ok{background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;color:#166534}
    .err{background:#fef2f2;border:1px solid #fecaca;padding:16px;border-radius:8px;color:#991b1b}
    a{color:#4f8ef7}</style></head><body>';
    if ($ok) {
        echo '<div class="ok">✅ Pagina <strong>' . htmlspecialchars($_POST['page_name']) . '</strong> connessa con successo!</div>';
        echo '<p><a href="/dashboard.html">→ Vai al dashboard</a></p>';
    } else {
        echo '<div class="err">❌ Errore: ' . htmlspecialchars($result['error'] ?? 'Errore sconosciuto') . '</div>';
        echo '<p><a href="/connect_page.php">← Riprova</a></p>';
    }
    echo '</body></html>';
    exit;
}

// ── Step 2: callback da Facebook con code ──────────────────────────
if (isset($_GET['code'])) {
    $code = $_GET['code'];

    // Scambia code con user token
    $tokenUrl = "https://graph.facebook.com/v19.0/oauth/access_token?"
        . "client_id={$appId}"
        . "&redirect_uri=" . urlencode($redirectUri)
        . "&client_secret={$appSecret}"
        . "&code=" . urlencode($code);

    $tokenData = json_decode(file_get_contents($tokenUrl), true);
    $userToken = $tokenData['access_token'] ?? null;

    if (!$userToken) {
        // Non esporre il payload grezzo di Facebook (può contenere dettagli interni):
        // logga lato server e mostra un messaggio generico.
        error_log('connect_page: scambio code→token fallito: ' . json_encode($tokenData));
        http_response_code(502);
        die('Errore durante l\'autenticazione con Facebook. Riprova dal dashboard.');
    }

    // Recupera FB user ID (per proteggerlo da ban futuri)
    $meUrl    = "https://graph.facebook.com/v19.0/me?access_token={$userToken}&fields=id";
    $meData   = json_decode(file_get_contents($meUrl), true);
    $ownerFbId = $meData['id'] ?? null;

    // Recupera pagine gestite
    $pagesUrl  = "https://graph.facebook.com/v19.0/me/accounts"
        . "?access_token={$userToken}"
        . "&fields=id,name,access_token";
    $pagesData = json_decode(file_get_contents($pagesUrl), true);
    $pages     = $pagesData['data'] ?? [];

    ?>
    <!DOCTYPE html>
    <html>
    <head>
    <meta charset="UTF-8">
    <title>Connetti pagina Facebook</title>
    <style>
      body { font-family: sans-serif; max-width: 600px; margin: 40px auto; padding: 20px; background: #f8fafc; }
      h2   { font-size: 18px; margin-bottom: 16px; }
      .card { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 12px; display: flex; align-items: center; justify-content: space-between; gap: 12px; }
      .page-name { font-weight: 600; font-size: 14px; }
      .page-id   { font-size: 11px; color: #94a3b8; margin-top: 2px; }
      .btn { padding: 8px 16px; background: #1877f2; color: #fff; border: none; border-radius: 8px; font-size: 13px; font-weight: 600; cursor: pointer; }
      .btn:hover { background: #1565c0; }
      .token-box { background: #fff; border: 1px solid #e2e8f0; border-radius: 10px; padding: 16px 20px; margin-bottom: 20px; }
      .token-box label { font-size: 12px; font-weight: 600; color: #64748b; display: block; margin-bottom: 6px; text-transform: uppercase; letter-spacing: .05em; }
      .token-box input { width: 100%; padding: 8px 10px; border: 1px solid #e2e8f0; border-radius: 6px; font-size: 12px; font-family: monospace; }
      .empty { color: #94a3b8; font-size: 13px; }
      .tip { font-size: 12px; color: #64748b; margin-top: 6px; }
    </style>
    </head>
    <body>

    <h2>Connetti una pagina Facebook</h2>

    <?php if (empty($pages)): ?>
        <p class="empty">Nessuna pagina trovata per questo account Facebook.<br>
        Assicurati di essere amministratore di almeno una pagina.</p>
    <?php else: ?>

        <div class="token-box">
            <label>JWT Token (dal dashboard)</label>
            <input type="text" id="jwt_input" placeholder="Incolla qui il tuo token JWT...">
            <p class="tip">Vai su <a href="/dashboard.html" target="_blank">dashboard</a> → apri console browser (F12) → digita: <code>localStorage.getItem('mh_token')</code></p>
        </div>

        <?php foreach ($pages as $page): ?>
        <div class="card">
            <div>
                <div class="page-name"><?= htmlspecialchars($page['name']) ?></div>
                <div class="page-id">ID: <?= htmlspecialchars($page['id']) ?></div>
            </div>
            <form method="POST" onsubmit="document.getElementById('tok_<?= $page['id'] ?>').value = document.getElementById('jwt_input').value">
                <input type="hidden" name="page_id"           value="<?= htmlspecialchars($page['id']) ?>">
                <input type="hidden" name="page_name"         value="<?= htmlspecialchars($page['name']) ?>">
                <input type="hidden" name="page_access_token" value="<?= htmlspecialchars($page['access_token']) ?>">
                <input type="hidden" name="owner_fb_id"       value="<?= htmlspecialchars($ownerFbId ?? '') ?>">
                <input type="hidden" name="jwt_token" id="tok_<?= $page['id'] ?>">
                <button type="submit" class="btn">Connetti</button>
            </form>
        </div>
        <?php endforeach; ?>

    <?php endif; ?>

    </body>
    </html>
    <?php
    exit;
}

// ── Step 1b: connessione diretta tramite page token ────────────────
if ($_SERVER['REQUEST_METHOD'] === 'POST' && isset($_POST['direct_page_id'])) {
    $jwtToken  = $_POST['jwt_token']      ?? '';
    $pageId    = trim($_POST['direct_page_id']    ?? '');
    $pageToken = trim($_POST['direct_page_token'] ?? '');

    if ($pageId === '' || $pageToken === '' || $jwtToken === '') {
        $directError = 'Tutti i campi sono obbligatori.';
    } else {
        $ch = curl_init($apiBase . '/pages/connect-direct');
        curl_setopt_array($ch, [
            CURLOPT_POST           => true,
            CURLOPT_POSTFIELDS     => json_encode(['page_id' => $pageId, 'page_token' => $pageToken]),
            CURLOPT_HTTPHEADER     => [
                'Content-Type: application/json',
                'Authorization: Bearer ' . $jwtToken,
            ],
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_SSL_VERIFYPEER => true,
        ]);
        $result = json_decode(curl_exec($ch), true);
        curl_close($ch);

        echo '<!DOCTYPE html><html><head><meta charset="UTF-8">
        <style>body{font-family:sans-serif;max-width:600px;margin:40px auto;padding:20px}
        .ok{background:#f0fdf4;border:1px solid #bbf7d0;padding:16px;border-radius:8px;color:#166534}
        .err{background:#fef2f2;border:1px solid #fecaca;padding:16px;border-radius:8px;color:#991b1b}
        a{color:#4f8ef7}</style></head><body>';
        if (isset($result['id'])) {
            echo '<div class="ok">✅ Pagina <strong>' . htmlspecialchars($result['page_name'] ?? $pageId) . '</strong> connessa con successo!</div>';
            echo '<p><a href="/dashboard.html">→ Vai al dashboard</a></p>';
        } else {
            echo '<div class="err">❌ Errore: ' . htmlspecialchars($result['error'] ?? 'Errore sconosciuto') . '</div>';
            echo '<p><a href="/connect_page.php">← Riprova</a></p>';
        }
        echo '</body></html>';
        exit;
    }
}

// ── Step 1: redirect a Facebook Login ─────────────────────────────
// Facebook Login for Business uses `config_id` (referring to a saved
// configuration in the Meta app dashboard) instead of the traditional
// `scope` parameter. The configuration must include pages_manage_metadata
// and related permissions for webhook subscription to work.
$loginUrl = "https://www.facebook.com/v19.0/dialog/oauth?"
    . "client_id={$appId}"
    . "&redirect_uri=" . urlencode($redirectUri)
    . "&config_id=26330044440000862"
    . "&response_type=code";

?>
<!DOCTYPE html>
<html>
<head>
<meta charset="UTF-8">
<title>Connetti pagina Facebook</title>
<style>
  body { font-family: sans-serif; max-width: 400px; margin: 80px auto; padding: 20px; text-align: center; }
  h2   { font-size: 20px; margin-bottom: 8px; }
  p    { color: #64748b; font-size: 14px; margin-bottom: 24px; }
  .btn { display: inline-flex; align-items: center; gap: 10px; padding: 12px 24px; background: #1877f2; color: #fff; border-radius: 10px; font-size: 15px; font-weight: 600; text-decoration: none; }
  .btn:hover { background: #1565c0; }
</style>
</head>
<body>
<h2>Connetti una pagina Facebook</h2>
<p>Accedi con Facebook per vedere le pagine che gestisci e collegarle al sistema di moderazione.</p>
<a href="<?= htmlspecialchars($loginUrl) ?>" class="btn">
  Accedi con Facebook
</a>

<hr style="margin:40px 0;border:none;border-top:1px solid #e2e8f0">

<h3 style="font-size:15px;margin-bottom:6px">Connessione tramite token diretto</h3>
<p style="font-size:13px;color:#64748b;margin-bottom:16px">
  Usa questa opzione se la pagina è gestita tramite Business Manager o hai già un page token
  generato dal <a href="https://developers.facebook.com/tools/explorer" target="_blank" rel="noopener">Graph API Explorer</a>.
</p>

<?php if (!empty($directError)): ?>
<div style="background:#fef2f2;border:1px solid #fecaca;padding:12px;border-radius:8px;color:#991b1b;margin-bottom:16px;font-size:13px">
  <?= htmlspecialchars($directError) ?>
</div>
<?php endif; ?>

<form method="POST" style="background:#fff;border:1px solid #e2e8f0;border-radius:10px;padding:20px;display:flex;flex-direction:column;gap:12px">
  <input type="hidden" name="direct_page_id" value="placeholder">
  <div>
    <label style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:4px">JWT Token (dal dashboard)</label>
    <input name="jwt_token" type="text" placeholder="Incolla il token JWT..." style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:monospace;box-sizing:border-box">
    <p style="font-size:11px;color:#94a3b8;margin:4px 0 0">Dashboard → console browser (F12) → <code>localStorage.getItem('mh_token')</code></p>
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:4px">Page ID</label>
    <input name="direct_page_id" type="text" placeholder="es. 123456789012345" style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:13px;box-sizing:border-box">
  </div>
  <div>
    <label style="font-size:11px;font-weight:600;color:#64748b;text-transform:uppercase;letter-spacing:.05em;display:block;margin-bottom:4px">Page Token</label>
    <input name="direct_page_token" type="text" placeholder="EAABsb..." style="width:100%;padding:8px 10px;border:1px solid #e2e8f0;border-radius:6px;font-size:12px;font-family:monospace;box-sizing:border-box">
    <p style="font-size:11px;color:#94a3b8;margin:4px 0 0">Graph API Explorer → scegli la pagina → Genera token con <code>pages_manage_metadata, pages_moderate, pages_read_engagement</code></p>
  </div>
  <button type="submit" style="padding:10px 20px;background:#1877f2;color:#fff;border:none;border-radius:8px;font-size:14px;font-weight:600;cursor:pointer;align-self:flex-start">Connetti con token</button>
</form>
</body>
</html>