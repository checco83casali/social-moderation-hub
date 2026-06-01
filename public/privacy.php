<?php
// public/privacy.php
// Privacy policy — dati organizzazione letti da app_settings nel DB.
// Può essere servito in due modi:
//   1. Via route Slim  GET /privacy  (incluso con require da index.php)
//   2. Direttamente via web server se il .htaccess esclude privacy.php dal rewrite
// In entrambi i casi gestisce correttamente autoload e connessione DB.

declare(strict_types=1);

// Carica autoload solo se non già caricato (caso standalone)
if (!class_exists(\Illuminate\Database\Capsule\Manager::class)) {
    require __DIR__ . '/../vendor/autoload.php';
}

// Carica .env solo se non già caricato
if (empty($_ENV['DB_HOST'])) {
    $dotenv = Dotenv\Dotenv::createImmutable(__DIR__ . '/..');
    $dotenv->load();
}

// ── Leggi impostazioni dal DB ──────────────────────────────────────
// Usa la connessione Capsule già attiva (caso Slim) oppure ne crea una nuova
function privacySetting(string $key, string $default = ''): string {
    static $cache = null;
    if ($cache === null) {
        try {
            // Prova a usare la connessione già attiva (caso Slim)
            $rows = \Illuminate\Database\Capsule\Manager::table('app_settings')->get();
        } catch (\Throwable) {
            // Connessione non attiva — avvia Capsule standalone
            try {
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
                $rows = \Illuminate\Database\Capsule\Manager::table('app_settings')->get();
            } catch (\Throwable) {
                $rows = collect([]);
            }
        }
        $cache = [];
        foreach ($rows as $row) {
            $cache[$row->key] = $row->value;
        }
    }
    $val = $cache[$key] ?? $default;
    return htmlspecialchars($val, ENT_QUOTES, 'UTF-8');
}

$orgName     = privacySetting('privacy_org_name',             '[Your Organisation]');
$orgAddress  = privacySetting('privacy_org_address',          '[Registered address]');
$orgEmail    = privacySetting('privacy_org_email',            'privacy@example.com');
$orgCountry  = privacySetting('privacy_org_country',          '[Country / Jurisdiction]');
$policyDate  = privacySetting('privacy_policy_date',          date('d F Y') . ' / ' . date('d F Y'));
$supervisory = privacySetting('privacy_supervisory_authority', '[Supervisory authority]');
$appUrl      = rtrim(privacySetting('app_url', 'https://yourdomain.com'), '/');
$appHost     = parse_url($appUrl, PHP_URL_HOST) ?: $appUrl;
$publicPolicyUrl = $appUrl . '/public/policy';

// Estrai le due date (IT / EN) dal campo unico "DD mese YYYY / DD Month YYYY"
$dates   = explode('/', $policyDate, 2);
$dateIt  = trim($dates[0] ?? $policyDate);
$dateEn  = trim($dates[1] ?? $policyDate);

// Estrai IT/EN dal campo country
$countries = explode('/', $orgCountry, 2);
$countryIt = trim($countries[0] ?? $orgCountry);
$countryEn = trim($countries[1] ?? $orgCountry);

?>
<!DOCTYPE html>
<html lang="it">
<head>
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Informativa sulla Privacy / Privacy Policy – Social Moderation Hub</title>
  <link rel="icon" type="image/svg+xml" href="/favicon.svg">
  <!--
    ═══════════════════════════════════════════════════════════════
    Social Moderation Hub — Privacy Policy Template
    ═══════════════════════════════════════════════════════════════
    Author  : Francesco Casali
    License : MIT — https://opensource.org/licenses/MIT
    Project : https://github.com/checco83casali/social-moderation-hub

    OPERATOR INSTRUCTIONS
    ─────────────────────
    This file is a TEMPLATE. Before publishing it on your installation:

    1. Replace all occurrences of "<?= $orgName ?>" with your legal entity name.
    2. Replace "<?= $orgAddress ?>"
       with your registered address.
    3. Replace the default contact / DPO e-mail address ("<?= $orgEmail ?>") with
       your actual privacy contact.
    4. Replace "<?= $appHost ?>" with your installation hostname.
    5. Update all dates ("<?= $dateIt ?>") to your actual go-live date.
    6. Replace "Repubblica di San Marino" / "Republic of San Marino" with
       your country / jurisdiction.
    7. Adjust the supervisory authority reference in §8 / §8 EN to the one
       competent for your country.
    8. Have the document reviewed by your legal counsel before publishing.

    AUTOMATED GENERATION
    ────────────────────
    You can also generate a pre-filled version via the API endpoint:
      GET /public/privacy-generator?org_name=...&org_email=...&app_url=...&lang=it|en|both
    The endpoint will replace all placeholders and return a downloadable HTML file.

    ═══════════════════════════════════════════════════════════════
  -->
  <style>
    *, *::before, *::after { box-sizing: border-box; }
    body {
      font-family: 'Helvetica Neue', Helvetica, Arial, sans-serif;
      font-size: 15px;
      line-height: 1.7;
      color: #222;
      max-width: 860px;
      margin: 0 auto;
      padding: 2rem 1.25rem 4rem;
      background: #fff;
    }
    h1 { font-size: 1.6rem; margin-bottom: 0.25rem; }
    h2 { font-size: 1.15rem; margin-top: 2rem; margin-bottom: 0.4rem;
         border-left: 4px solid #1877f2; padding-left: 0.6rem; }
    h3 { font-size: 1rem; margin-top: 1.4rem; margin-bottom: 0.25rem; color: #444; }
    p  { margin: 0.5rem 0 0.8rem; }
    ul { margin: 0.4rem 0 0.8rem 1.4rem; }
    li { margin-bottom: 0.3rem; }
    table {
      width: 100%; border-collapse: collapse; font-size: 0.92rem;
      margin: 0.8rem 0 1.2rem;
    }
    th { background: #f0f4ff; text-align: left; padding: 0.5rem 0.7rem;
         border: 1px solid #ccd; }
    td { padding: 0.45rem 0.7rem; border: 1px solid #dde; vertical-align: top; }
    tr:nth-child(even) td { background: #fafbff; }
    .lang-section { margin-bottom: 3rem; }
    .divider {
      border: none; border-top: 2px dashed #ccd;
      margin: 3rem 0;
    }
    .badge {
      display: inline-block; background: #e8f0fe; color: #1a56db;
      border-radius: 4px; font-size: 0.8rem; padding: 0.1rem 0.5rem;
      margin-left: 0.4rem; vertical-align: middle;
    }
    .highlight {
      background: #fff8e1; border-left: 4px solid #f59e0b;
      padding: 0.6rem 0.9rem; margin: 0.8rem 0; border-radius: 0 4px 4px 0;
    }
    footer { font-size: 0.82rem; color: #888; margin-top: 3rem; }
    @media (max-width: 600px) {
      table { font-size: 0.82rem; }
      h1 { font-size: 1.3rem; }
    }
  </style>
</head>
<body>

<!-- ═══════════════════════════════════════════════════════════════
     SEZIONE ITALIANA
     ═══════════════════════════════════════════════════════════════ -->
<div class="lang-section" lang="it">

<h1>Informativa sul Trattamento dei Dati Personali</h1>
<p><em>ai sensi degli artt. 13–14 del Regolamento (UE) 2016/679 (GDPR)</em></p>
<p><strong>Versione:</strong> 1.0 &nbsp;|&nbsp; <strong>Data di efficacia:</strong> <?= $dateIt ?> &nbsp;|&nbsp;
   <strong>Ultima revisione:</strong> <?= $dateIt ?></p>

<h2>1. Titolare del Trattamento</h2>
<p><strong><?= $orgName ?></strong><br>
<?= $orgAddress ?><br>
E-mail: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a><br>
PEC / contatto DPO: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a></p>

<h2>2. Che cos'è il Social Moderation Hub e a chi si rivolge</h2>
<p>Il <strong>Social Moderation Hub</strong> è un sistema software (di seguito «Piattaforma») installato e gestito
autonomamente dal Titolare per moderare i commenti pubblicati su Pagine Facebook di sua proprietà o
amministrate per conto di terzi. La presente informativa è rivolta a:</p>
<ul>
  <li><strong>Utenti di Facebook</strong> che pubblicano commenti su Pagine gestite tramite la Piattaforma.</li>
  <li><strong>Amministratori / moderatori umani</strong> che accedono alla dashboard della Piattaforma.</li>
</ul>

<h2>3. Categorie di dati trattati e fonti</h2>

<h3>3.1 Dati degli utenti Facebook (commentatori)</h3>
<table>
  <thead>
    <tr><th>Categoria</th><th>Dettaglio</th><th>Fonte</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Identificativo di piattaforma</td>
      <td>ID utente Facebook (non il nome reale)</td>
      <td>Meta Graph API (webhook)</td>
    </tr>
    <tr>
      <td>Nome visualizzato</td>
      <td>Nome pubblico del profilo al momento del commento</td>
      <td>Meta Graph API</td>
    </tr>
    <tr>
      <td>Contenuto del commento</td>
      <td>Testo del commento pubblicato sulla Pagina, inclusi eventuali dati personali inseriti volontariamente dall'interessato</td>
      <td>Meta Graph API (webhook)</td>
    </tr>
    <tr>
      <td>Metadati comportamentali</td>
      <td>Numero di violazioni pregresse; stato ban; fascia di anzianità account; fascia follower; stato verifica account</td>
      <td>Calcolati/elaborati dalla Piattaforma e da Meta Graph API</td>
    </tr>
    <tr>
      <td>Token di appello</td>
      <td>Token crittografico temporaneo (firmato HMAC, 30 giorni) per la procedura di contestazione</td>
      <td>Generato dalla Piattaforma</td>
    </tr>
  </tbody>
</table>

<h3>3.2 Dati degli amministratori / moderatori</h3>
<table>
  <thead>
    <tr><th>Categoria</th><th>Dettaglio</th><th>Fonte</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Credenziali di accesso</td>
      <td>Indirizzo e-mail aziendale, token SSO (Microsoft Azure AD / Entra ID)</td>
      <td>Fornite dall'utente o dal provider SSO</td>
    </tr>
    <tr>
      <td>Log di moderazione</td>
      <td>Decisioni prese, note, timestamp</td>
      <td>Inserite dall'amministratore nella dashboard</td>
    </tr>
    <tr>
      <td>Dati di sessione</td>
      <td>JWT, indirizzo IP, user-agent</td>
      <td>Raccolti automaticamente</td>
    </tr>
  </tbody>
</table>

<h2>4. Finalità e basi giuridiche del trattamento</h2>

<table>
  <thead>
    <tr><th>Finalità</th><th>Base giuridica (GDPR)</th><th>Note</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Moderazione automatizzata dei commenti per proteggere la sicurezza e l'ordine pubblico sulla Pagina</td>
      <td><strong>Art. 6.1.f</strong> – Legittimo interesse del Titolare e degli utenti della Pagina</td>
      <td>Il Titolare ha condotto una valutazione di bilanciamento (LIA): l'interesse prevalente è prevenire truffe, spam, contenuti illeciti e abusi verso terzi. L'interessato può opporsi (art. 21 GDPR).</td>
    </tr>
    <tr>
      <td>Gestione dei ban e storico delle violazioni (finalità preventiva / recidiva)</td>
      <td><strong>Art. 6.1.f</strong> – Legittimo interesse</td>
      <td>Proporzionato; i dati di ban vengono cancellati dopo 24 mesi dall'ultima violazione.</td>
    </tr>
    <tr>
      <td>Procedura di appello (contestazione della moderazione)</td>
      <td><strong>Art. 6.1.b</strong> – Esecuzione di un rapporto precontrattuale / misure su richiesta dell'interessato</td>
      <td>L'interessato avvia volontariamente la procedura.</td>
    </tr>
    <tr>
      <td>Accesso e autenticazione degli amministratori</td>
      <td><strong>Art. 6.1.b</strong> – Esecuzione del contratto con l'operatore della Pagina</td>
      <td></td>
    </tr>
    <tr>
      <td>Adempimenti di legge (es. ordini dell'autorità)</td>
      <td><strong>Art. 6.1.c</strong> – Obbligo legale</td>
      <td></td>
    </tr>
    <tr>
      <td>Miglioramento continuo del sistema di moderazione (apprendimento dai feedback umani)</td>
      <td><strong>Art. 6.1.f</strong> – Legittimo interesse</td>
      <td>I feedback sono memorizzati localmente; nessun dato identificativo è comunicato a terzi per training.</td>
    </tr>
  </tbody>
</table>

<h2>5. Trattamento automatizzato con Intelligenza Artificiale <span class="badge">Art. 22 GDPR</span></h2>

<div class="highlight">
  <strong>Informazione essenziale:</strong> il contenuto dei commenti pubblicati sulle Pagine gestite è sottoposto a un processo automatizzato di analisi tramite modelli di Intelligenza Artificiale (IA). Tale processo può produrre effetti giuridicamente rilevanti sull'interessato (es. nascondimento temporaneo del commento, applicazione di un ban).
</div>

<h3>5.1 Come funziona il sistema</h3>
<p>La Piattaforma implementa una pipeline di moderazione ibrida AI/umana su tre livelli:</p>
<ol>
  <li><strong>Livello 1 – AI rapida (Claude Haiku):</strong> analisi iniziale del commento. Se la decisione è chiara e ad alta confidenza, viene applicata automaticamente.</li>
  <li><strong>Livello 2 – AI approfondita (Claude Sonnet):</strong> attivata in caso di bassa confidenza del Livello 1 per un'analisi più accurata.</li>
  <li><strong>Livello 3 – Revisione umana:</strong> attivata quando entrambi i livelli AI producono un esito incerto. Un moderatore umano prende la decisione finale.</li>
</ol>
<p>Esiti possibili della moderazione: approvazione (nessuna azione), nascondimento temporaneo (visibile solo all'autore), segnalazione per revisione legale, ban dell'utente.</p>

<h3>5.2 Dati inviati al servizio AI</h3>
<p>Al servizio di Intelligenza Artificiale esterno vengono trasmessi <strong>esclusivamente</strong>:</p>
<ul>
  <li>Il <strong>testo del commento</strong> da moderare (inclusi eventuali dati personali inseriti volontariamente dall'utente nel testo).</li>
  <li>Uno <strong>pseudonimo non reversibile</strong> dell'autore (es. «User-a3f9c2»), derivato dall'ID Facebook tramite HMAC-SHA256 con chiave segreta locale — non decifrabile da terzi, incluso il fornitore AI.</li>
  <li>Segnali di rischio <strong>aggregati e categorizzati</strong> (non numerici): fascia di anzianità account, fascia follower, stato verifica, numero di violazioni pregresse, stato ban.</li>
</ul>
<p><strong>Non vengono mai trasmessi al fornitore AI:</strong> nome reale, ID Facebook originale, URL del profilo, dati di contatto.</p>

<h3>5.3 Fornitore del servizio AI – Responsabile del trattamento</h3>
<p>I modelli di IA sono forniti da <strong>Anthropic PBC</strong> (340 Pine Street, Suite 323, San Francisco, CA 94104, USA), in qualità di <strong>Responsabile del trattamento</strong> ai sensi dell'art. 28 GDPR, vincolato da apposito Data Processing Agreement (DPA).</p>
<p>Garanzie applicabili:</p>
<ul>
  <li>I dati trasmessi tramite API <strong>non vengono utilizzati per addestrare i modelli</strong> di Anthropic (per impostazione predefinita, salvo accordo esplicito contrario).</li>
  <li>Il trasferimento verso gli USA è coperto dalle <strong>Clausole Contrattuali Standard (SCC)</strong> adottate dalla Commissione Europea ai sensi dell'art. 46.2.c GDPR.</li>
  <li>Anthropic applica misure di sicurezza conformi agli standard SOC 2 Type II.</li>
  <li>Informativa privacy di Anthropic: <a href="https://www.anthropic.com/privacy" target="_blank" rel="noopener">anthropic.com/privacy</a></li>
</ul>

<h3>5.4 Logica della decisione automatizzata e misure di salvaguardia</h3>
<p>Il sistema non si basa su caratteristiche protette (origine etnica, opinioni politiche, religione, ecc.) per prendere decisioni. La moderazione valuta esclusivamente il <em>contenuto testuale</em> del commento rispetto alle policy della Pagina (es. prevenzione truffe, spam, contenuti violenti).</p>
<p>Misure di salvaguardia implementate:</p>
<ul>
  <li>Le decisioni ad alta confidenza sono revisionate a campione dagli amministratori.</li>
  <li><strong>Revisione cieca (blind review):</strong> quando un commento è escalato alla revisione umana, il moderatore vede esclusivamente uno pseudonimo interno (es. «Utente #4821»), mai il nome reale Facebook. Il nome reale non viene mai trasmesso al client durante il processo di revisione.</li>
  <li>Qualsiasi decisione di nascondimento può essere contestata tramite la <strong>procedura di appello</strong> (vedi §8).</li>
  <li>Le decisioni di ban definitivo sono sempre soggette a revisione umana preventiva.</li>
  <li>I feedback dei moderatori umani retroalimentano il sistema per ridurre errori futuri.</li>
</ul>
<p><strong>Diritto di non essere soggetto a decisione automatizzata:</strong> l'interessato ha il diritto di richiedere l'intervento di un moderatore umano per qualsiasi decisione che lo riguardi, di esprimere la propria opinione e di contestare la decisione, scrivendo a <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a> o utilizzando il link di appello ricevuto.</p>

<h3>5.5 Regole di moderazione pubbliche</h3>
<p>In ossequio al principio di trasparenza (art. 5.1.a GDPR) e al diritto all'informazione sulle decisioni automatizzate (art. 22.3 GDPR), le regole di moderazione attualmente in vigore — ovvero i criteri con cui il sistema AI valuta i commenti — sono consultabili pubblicamente al seguente indirizzo, senza necessità di autenticazione:</p>
<p style="margin-left:1rem;">
  <a href="<?= $publicPolicyUrl ?>" target="_blank" rel="noopener">
    <?= $publicPolicyUrl ?>
  </a>
</p>
<p>La risposta include: nome e versione della policy attiva, le regole operative (categorie di violazione, logica di escalation, criteri di fact-check), la descrizione della pipeline AI/umana e una nota sulla privacy dei dati trasmessi. Il blocco tecnico di formattazione dell'output — che definisce il contratto interno con il modello AI — è escluso dalla visualizzazione pubblica in quanto irrilevante per l'interessato e potenzialmente utile ad aggirare i filtri di moderazione.</p>

<h2>6. Trasferimenti internazionali di dati</h2>
<table>
  <thead>
    <tr><th>Destinatario</th><th>Paese</th><th>Garanzie</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Anthropic PBC (servizio AI)</td>
      <td>USA</td>
      <td>Clausole Contrattuali Standard (SCC) – Decisione UE 2021/914</td>
    </tr>
    <tr>
      <td>Meta Platforms Ireland Ltd. (Facebook Graph API)</td>
      <td>UE / USA</td>
      <td>SCC; Data Transfer Framework UE-USA; Privacy Policy Meta</td>
    </tr>
    <tr>
      <td>Microsoft Corporation (Azure AD / Entra ID – solo per amministratori)</td>
      <td>UE / USA</td>
      <td>SCC; Microsoft Data Protection Addendum</td>
    </tr>
  </tbody>
</table>

<h2>7. Conservazione dei dati</h2>
<table>
  <thead>
    <tr><th>Categoria di dati</th><th>Periodo di conservazione</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Commenti approvati (log interno)</td>
      <td>12 mesi dalla ricezione, poi cancellazione automatica</td>
    </tr>
    <tr>
      <td>Commenti nascosti + log di moderazione</td>
      <td>24 mesi (necessari per gestire appelli e contenziosi)</td>
    </tr>
    <tr>
      <td>Storico violazioni e ban</td>
      <td>24 mesi dall'ultima violazione; ban revocato anticipatamente su richiesta motivata</td>
    </tr>
    <tr>
      <td>Token di appello</td>
      <td>30 giorni dalla generazione</td>
    </tr>
    <tr>
      <td>Log di accesso amministratori</td>
      <td>12 mesi</td>
    </tr>
    <tr>
      <td>Dati AI (testi inviati ad Anthropic)</td>
      <td>Trattati in tempo reale; non conservati da Anthropic per finalità di training; retention di sicurezza breve secondo le policy Anthropic</td>
    </tr>
  </tbody>
</table>

<h2>8. Diritti dell'interessato</h2>
<p>Ai sensi degli artt. 15–22 GDPR, l'interessato ha il diritto di:</p>
<ul>
  <li><strong>Accesso (art. 15):</strong> ottenere conferma che siano trattati dati che lo riguardano e riceverne copia.</li>
  <li><strong>Rettifica (art. 16):</strong> rettificare dati inesatti.</li>
  <li><strong>Cancellazione (art. 17):</strong> ottenere la cancellazione dei dati («diritto all'oblio»), salvo obblighi di conservazione prevalenti.</li>
  <li><strong>Limitazione (art. 18):</strong> ottenere la limitazione del trattamento in determinati casi.</li>
  <li><strong>Portabilità (art. 20):</strong> ricevere i propri dati in formato strutturato e leggibile da macchina (ove applicabile).</li>
  <li><strong>Opposizione (art. 21):</strong> opporsi al trattamento basato sul legittimo interesse; il Titolare valuterà la richiesta e potrà rifiutarla solo per motivi legittimi cogenti.</li>
  <li><strong>Non essere soggetto a decisione automatizzata (art. 22):</strong> richiedere revisione umana di qualsiasi decisione automatizzata che lo riguardi.</li>
  <li><strong>Appello immediato:</strong> contestare la moderazione tramite il link ricevuto nel commento di notifica (disponibile per 30 giorni), senza necessità di identificarsi ulteriormente.</li>
</ul>
<p>Per esercitare i propri diritti: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a>. Il Titolare risponde entro 30 giorni (prorogabili a 90 in casi complessi, con comunicazione motivata).</p>
<p>L'interessato ha altresì il diritto di proporre reclamo al <strong>Garante per la Protezione dei Dati Personali</strong> (autorità capofila per la <?= $countryIt ?>: <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">garanteprivacy.it</a>).</p>

<h2>9. Sicurezza dei dati</h2>
<p>Il Titolare adotta misure tecniche e organizzative adeguate ai sensi dell'art. 32 GDPR, tra cui:</p>
<ul>
  <li>Crittografia in transito (TLS 1.2+) per tutte le comunicazioni API.</li>
  <li>Pseudonimizzazione degli identificatori utente prima della trasmissione al servizio AI (HMAC-SHA256).</li>
  <li>Hashing dei contenuti (SHA-256) per riconoscere le ripubblicazioni identiche.</li>
  <li>Autenticazione multi-fattore per gli amministratori (SSO Azure AD).</li>
  <li>Accesso ai dati limitato al personale autorizzato secondo il principio del minimo privilegio.</li>
  <li>Log di audit per tutte le operazioni di moderazione.</li>
  <li>Procedure di gestione delle violazioni dei dati ai sensi dell'art. 33 GDPR.</li>
</ul>

<h2>10. Cookie e dati di navigazione (dashboard amministratori)</h2>
<p>La dashboard della Piattaforma utilizza esclusivamente cookie tecnici di sessione (JWT), strettamente necessari al funzionamento. Non vengono utilizzati cookie di profilazione o di terze parti a fini pubblicitari. Non è richiesto il consenso ai cookie per la sola dashboard.</p>

<h2>11. Minori</h2>
<p>La Piattaforma non è destinata a raccogliere intenzionalmente dati di minori di 16 anni. Se il Titolare dovesse apprendere di aver trattato dati di un minore, provvederà alla loro immediata cancellazione. Segnalazioni: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a>.</p>

<h2>12. Modifiche alla presente informativa</h2>
<p>Il Titolare si riserva di aggiornare la presente informativa in caso di modifiche normative, tecnologiche o operative. La versione aggiornata sarà pubblicata su questa pagina con nuova data di efficacia. Per modifiche sostanziali, gli amministratori saranno notificati via e-mail con almeno 15 giorni di preavviso.</p>

<h2>13. Contatti</h2>
<p>Per qualsiasi questione relativa al trattamento dei dati personali:<br>
<strong><?= $orgName ?></strong> – <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a></p>

</div><!-- /lang-section IT -->

<hr class="divider">

<!-- ═══════════════════════════════════════════════════════════════
     ENGLISH SECTION
     ═══════════════════════════════════════════════════════════════ -->
<div class="lang-section" lang="en">

<h1>Privacy Policy – Personal Data Processing Notice</h1>
<p><em>pursuant to Articles 13–14 of Regulation (EU) 2016/679 (GDPR)</em></p>
<p><strong>Version:</strong> 1.0 &nbsp;|&nbsp; <strong>Effective date:</strong> <?= $dateEn ?> &nbsp;|&nbsp;
   <strong>Last revised:</strong> <?= $dateEn ?></p>

<h2>1. Data Controller</h2>
<p><strong><?= $orgName ?></strong><br>
<?= $orgAddress ?><br>
E-mail: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a><br>
DPO contact: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a></p>

<h2>2. What is the Social Moderation Hub and who does this notice apply to?</h2>
<p>The <strong>Social Moderation Hub</strong> (hereinafter «Platform») is a self-hosted software system operated by the Data Controller to moderate comments posted on Facebook Pages owned or managed by the Controller. This notice applies to:</p>
<ul>
  <li><strong>Facebook users</strong> who post comments on Pages managed through the Platform.</li>
  <li><strong>Administrators / human moderators</strong> who access the Platform dashboard.</li>
</ul>

<h2>3. Categories of personal data and sources</h2>

<h3>3.1 Facebook users (commenters)</h3>
<table>
  <thead>
    <tr><th>Category</th><th>Detail</th><th>Source</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Platform identifier</td>
      <td>Facebook user ID (not the real name)</td>
      <td>Meta Graph API (webhook)</td>
    </tr>
    <tr>
      <td>Display name</td>
      <td>Public profile name at the time of the comment</td>
      <td>Meta Graph API</td>
    </tr>
    <tr>
      <td>Comment content</td>
      <td>Text of the comment published on the Page, including any personal data voluntarily included by the user</td>
      <td>Meta Graph API (webhook)</td>
    </tr>
    <tr>
      <td>Behavioural signals</td>
      <td>Number of prior violations; ban status; account age bracket; follower bracket; account verification status</td>
      <td>Computed by the Platform and Meta Graph API</td>
    </tr>
    <tr>
      <td>Appeal token</td>
      <td>Temporary cryptographic token (HMAC-signed, 30 days) for the appeal procedure</td>
      <td>Generated by the Platform</td>
    </tr>
  </tbody>
</table>

<h3>3.2 Administrators / moderators</h3>
<table>
  <thead>
    <tr><th>Category</th><th>Detail</th><th>Source</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Access credentials</td>
      <td>Corporate e-mail address, SSO token (Microsoft Azure AD / Entra ID)</td>
      <td>Provided by the user or the SSO provider</td>
    </tr>
    <tr>
      <td>Moderation log</td>
      <td>Decisions taken, notes, timestamps</td>
      <td>Entered by the administrator in the dashboard</td>
    </tr>
    <tr>
      <td>Session data</td>
      <td>JWT, IP address, user-agent</td>
      <td>Collected automatically</td>
    </tr>
  </tbody>
</table>

<h2>4. Purposes and legal bases for processing</h2>

<table>
  <thead>
    <tr><th>Purpose</th><th>Legal basis (GDPR)</th><th>Notes</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Automated moderation of comments to protect safety and public order on the Page</td>
      <td><strong>Art. 6.1.f</strong> – Legitimate interests of the Controller and Page users</td>
      <td>A Legitimate Interests Assessment (LIA) has been carried out. The prevailing interest is preventing scams, spam, illegal content and abuse. The data subject may object (Art. 21 GDPR).</td>
    </tr>
    <tr>
      <td>Ban management and violation history (preventive / recidivism purpose)</td>
      <td><strong>Art. 6.1.f</strong> – Legitimate interests</td>
      <td>Proportionate; ban data is deleted after 24 months from the last violation.</td>
    </tr>
    <tr>
      <td>Appeal procedure (contesting a moderation decision)</td>
      <td><strong>Art. 6.1.b</strong> – Steps taken at the data subject's request</td>
      <td>The data subject voluntarily initiates the procedure.</td>
    </tr>
    <tr>
      <td>Administrator access and authentication</td>
      <td><strong>Art. 6.1.b</strong> – Performance of a contract with the Page operator</td>
      <td></td>
    </tr>
    <tr>
      <td>Legal obligations (e.g. authority orders)</td>
      <td><strong>Art. 6.1.c</strong> – Legal obligation</td>
      <td></td>
    </tr>
    <tr>
      <td>Continuous improvement of the moderation system (learning from human feedback)</td>
      <td><strong>Art. 6.1.f</strong> – Legitimate interests</td>
      <td>Feedback is stored locally; no identifiable data is shared with third parties for AI training.</td>
    </tr>
  </tbody>
</table>

<h2>5. Automated processing and Artificial Intelligence <span class="badge">Art. 22 GDPR</span></h2>

<div class="highlight">
  <strong>Essential notice:</strong> comments posted on managed Pages are subject to automated analysis by Artificial Intelligence (AI) models. This process may produce legally significant effects on the data subject (e.g. temporary comment hiding, application of a ban).
</div>

<h3>5.1 How the system works</h3>
<p>The Platform implements a three-tier hybrid AI/human moderation pipeline:</p>
<ol>
  <li><strong>Tier 1 – Fast AI (Claude Haiku):</strong> initial comment analysis. If the decision is clear and high-confidence, it is applied automatically.</li>
  <li><strong>Tier 2 – Deep AI (Claude Sonnet):</strong> triggered when Tier 1 confidence is low, for more thorough analysis.</li>
  <li><strong>Tier 3 – Human review:</strong> triggered when both AI tiers produce an uncertain outcome. A human moderator makes the final decision.</li>
</ol>
<p>Possible outcomes: approval (no action), temporary hiding (visible only to the author), flagging for legal review, user ban.</p>

<h3>5.2 Data sent to the AI service</h3>
<p>Only the following is transmitted to the external AI service:</p>
<ul>
  <li>The <strong>comment text</strong> to be moderated (including any personal data voluntarily included by the user in the text).</li>
  <li>A <strong>non-reversible pseudonym</strong> for the author (e.g. «User-a3f9c2»), derived from the Facebook ID via HMAC-SHA256 with a local secret key — not decipherable by third parties, including the AI provider.</li>
  <li>Aggregated, <strong>bracketed risk signals</strong> (not raw values): account age bracket, follower bracket, verification status, number of prior violations, ban status.</li>
</ul>
<p><strong>Never transmitted to the AI provider:</strong> real name, original Facebook ID, profile URL, or contact details.</p>

<h3>5.3 AI service provider – Data Processor</h3>
<p>AI models are provided by <strong>Anthropic PBC</strong> (340 Pine Street, Suite 323, San Francisco, CA 94104, USA), acting as a <strong>Data Processor</strong> under Art. 28 GDPR, bound by a Data Processing Agreement (DPA).</p>
<p>Applicable safeguards:</p>
<ul>
  <li>Data transmitted via API is <strong>not used to train Anthropic's models</strong> (by default, unless otherwise explicitly agreed).</li>
  <li>The transfer to the USA is covered by the <strong>Standard Contractual Clauses (SCCs)</strong> adopted by the European Commission under Art. 46.2.c GDPR.</li>
  <li>Anthropic applies security measures compliant with SOC 2 Type II standards.</li>
  <li>Anthropic privacy policy: <a href="https://www.anthropic.com/privacy" target="_blank" rel="noopener">anthropic.com/privacy</a></li>
</ul>

<h3>5.4 Logic of automated decision-making and safeguards</h3>
<p>The system does not rely on protected characteristics (ethnic origin, political opinions, religion, etc.) to make decisions. Moderation evaluates exclusively the <em>textual content</em> of the comment against the Page's policies (e.g. scam prevention, spam, violent content).</p>
<p>Implemented safeguards:</p>
<ul>
  <li>High-confidence automated decisions are subject to sample review by administrators.</li>
  <li><strong>Blind review:</strong> when a comment is escalated to human review, the moderator sees only an internal pseudonym (e.g. «User #4821»), never the real Facebook name. The real name is never transmitted to the client during the review process.</li>
  <li>Any hiding decision can be contested via the <strong>appeal procedure</strong> (see §8).</li>
  <li>Permanent ban decisions are always subject to prior human review.</li>
  <li>Human moderator feedback is used to improve the system and reduce future errors.</li>
</ul>
<p><strong>Right not to be subject to automated decision-making:</strong> the data subject has the right to request human moderator intervention for any decision concerning them, to express their view, and to contest the decision, by writing to <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a> or using the appeal link received.</p>

<h3>5.5 Public moderation rules</h3>
<p>In accordance with the transparency principle (Art. 5.1.a GDPR) and the right to information on automated decisions (Art. 22.3 GDPR), the moderation rules currently in force — i.e. the criteria by which the AI system evaluates comments — are publicly available at the following address, without requiring authentication:</p>
<p style="margin-left:1rem;">
  <a href="<?= $publicPolicyUrl ?>" target="_blank" rel="noopener">
    <?= $publicPolicyUrl ?>
  </a>
</p>
<p>The response includes: the name and version of the active policy, the operational rules (violation categories, escalation logic, fact-check criteria), a description of the AI/human pipeline, and a privacy note on transmitted data. The technical output-formatting block — which defines the internal contract with the AI model — is excluded from public view as it is irrelevant to data subjects and could potentially be used to circumvent moderation filters.</p>

<h2>6. International data transfers</h2>
<table>
  <thead>
    <tr><th>Recipient</th><th>Country</th><th>Safeguards</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Anthropic PBC (AI service)</td>
      <td>USA</td>
      <td>Standard Contractual Clauses (SCCs) – EU Decision 2021/914</td>
    </tr>
    <tr>
      <td>Meta Platforms Ireland Ltd. (Facebook Graph API)</td>
      <td>EU / USA</td>
      <td>SCCs; EU-US Data Privacy Framework; Meta Privacy Policy</td>
    </tr>
    <tr>
      <td>Microsoft Corporation (Azure AD / Entra ID – admins only)</td>
      <td>EU / USA</td>
      <td>SCCs; Microsoft Data Protection Addendum</td>
    </tr>
  </tbody>
</table>

<h2>7. Data retention</h2>
<table>
  <thead>
    <tr><th>Data category</th><th>Retention period</th></tr>
  </thead>
  <tbody>
    <tr>
      <td>Approved comments (internal log)</td>
      <td>12 months from receipt, then automatic deletion</td>
    </tr>
    <tr>
      <td>Hidden comments + moderation log</td>
      <td>24 months (required for appeals and disputes)</td>
    </tr>
    <tr>
      <td>Violation history and bans</td>
      <td>24 months from last violation; bans may be lifted earlier upon reasoned request</td>
    </tr>
    <tr>
      <td>Appeal tokens</td>
      <td>30 days from generation</td>
    </tr>
    <tr>
      <td>Administrator access logs</td>
      <td>12 months</td>
    </tr>
    <tr>
      <td>AI data (texts sent to Anthropic)</td>
      <td>Processed in real time; not retained by Anthropic for training; short security retention per Anthropic's policies</td>
    </tr>
  </tbody>
</table>

<h2>8. Data subject rights</h2>
<p>Under Arts. 15–22 GDPR, data subjects have the right to:</p>
<ul>
  <li><strong>Access (Art. 15):</strong> obtain confirmation of whether data concerning them is being processed and receive a copy.</li>
  <li><strong>Rectification (Art. 16):</strong> correct inaccurate data.</li>
  <li><strong>Erasure (Art. 17):</strong> obtain deletion of their data («right to be forgotten»), subject to overriding retention obligations.</li>
  <li><strong>Restriction (Art. 18):</strong> obtain restriction of processing in certain circumstances.</li>
  <li><strong>Portability (Art. 20):</strong> receive their data in a structured, machine-readable format (where applicable).</li>
  <li><strong>Object (Art. 21):</strong> object to processing based on legitimate interests; the Controller will assess the request and may refuse only for compelling legitimate grounds.</li>
  <li><strong>Not to be subject to automated decision-making (Art. 22):</strong> request human review of any automated decision concerning them.</li>
  <li><strong>Immediate appeal:</strong> contest moderation decisions via the link received in the notification comment (available for 30 days), without needing to further identify themselves.</li>
</ul>
<p>To exercise rights: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a>. The Controller will respond within 30 days (extendable to 90 days for complex cases, with reasoned notice).</p>
<p>Data subjects also have the right to lodge a complaint with the competent <strong>supervisory authority</strong> (lead authority for the <?= $countryEn ?>: <a href="https://www.garanteprivacy.it" target="_blank" rel="noopener">garanteprivacy.it</a>).</p>

<h2>9. Security</h2>
<p>The Controller implements appropriate technical and organisational measures under Art. 32 GDPR, including:</p>
<ul>
  <li>Encryption in transit (TLS 1.2+) for all API communications.</li>
  <li>Pseudonymisation of user identifiers before transmission to the AI service (HMAC-SHA256).</li>
  <li>Content hashing (SHA-256) to detect identical re-posts.</li>
  <li>Multi-factor authentication for administrators (Azure AD SSO).</li>
  <li>Least-privilege access controls for all data operations.</li>
  <li>Audit logs for all moderation operations.</li>
  <li>Data breach management procedures under Art. 33 GDPR.</li>
</ul>

<h2>10. Cookies and browsing data (administrator dashboard)</h2>
<p>The Platform dashboard uses only strictly necessary technical session cookies (JWT). No profiling or third-party advertising cookies are used. No cookie consent is required for the dashboard alone.</p>

<h2>11. Minors</h2>
<p>The Platform is not intended to knowingly collect data from individuals under 16 years of age. If the Controller becomes aware that such data has been processed, it will be deleted immediately. Please report to: <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a>.</p>

<h2>12. Changes to this notice</h2>
<p>The Controller reserves the right to update this notice in response to regulatory, technological or operational changes. The updated version will be published on this page with a new effective date. For material changes, administrators will be notified by e-mail at least 15 days in advance.</p>

<h2>13. Contact</h2>
<p>For any matter relating to personal data processing:<br>
<strong><?= $orgName ?></strong> – <a href="mailto:<?= $orgEmail ?>"><?= $orgEmail ?></a></p>

</div><!-- /lang-section EN -->

<footer>
  <p>Social Moderation Hub – Privacy Policy Template v1.0 – Effective [operator: insert date]</p>
  <p>Template authored by <strong>Francesco Casali</strong> –
     Released under the <a href="https://opensource.org/licenses/MIT" target="_blank" rel="noopener">MIT License</a> –
     <a href="https://github.com/checco83casali/social-moderation-hub" target="_blank" rel="noopener">GitHub</a></p>
  <p><em>This document is a template. The operator of this installation is solely responsible
     for its accuracy, completeness, and compliance with applicable data protection laws.
     Generate a pre-filled version at <code>/public/privacy-generator</code>.</em></p>
</footer>

</body>
</html>