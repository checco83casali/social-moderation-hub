<?php
// public/lia.php
// Legitimate Interest Assessment (LIA) — valutazione di bilanciamento degli
// interessi a supporto della base giuridica art. 6.1.f GDPR.
// Generato automaticamente dal sistema. Non modificare manualmente.
// Chiamato da ModerationController::exportLia() con extract($vars).

// La LIA precede per definizione la decisione di avviare il trattamento: prima
// del go-live non esistono dati operativi (ban, appelli) da misurare, quindi il
// Balancing Test si appoggia alle garanzie progettuali, non a statistiche d'uso.
$isPreLaunch = ((int) $totComments === 0);
?><!DOCTYPE html>
<html lang="it">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LIA – <?= htmlspecialchars($orgName, ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>/assets/css/gdpr-lia.css">
</head>
<body>
<div class="page">

<!-- ═══════════════════════════════════════════════════════════════
     SEZIONE ITALIANA
     ═══════════════════════════════════════════════════════════════ -->
<div class="lang-section" lang="it">

  <!-- Document header -->
  <div class="doc-header">
    <div class="doc-title">Legitimate Interest Assessment (LIA)</div>
    <div class="doc-sub">Valutazione di bilanciamento degli interessi — a supporto della base giuridica art. 6(1)(f) Reg. UE 2016/679 (GDPR)</div>
    <div class="doc-meta">
      <span><strong>Titolare:</strong> <?= htmlspecialchars($orgName, ENT_QUOTES) ?></span>
      <span><strong>Sistema:</strong> Social Moderation Hub v<?= htmlspecialchars($appVersion, ENT_QUOTES) ?></span>
      <span><strong>Data redazione:</strong> <?= htmlspecialchars($today, ENT_QUOTES) ?></span>
      <span><strong>Versione documento:</strong> 1.0</span>
    </div>
  </div>

  <!-- 1. Titolare -->
  <h2>1. Titolare del trattamento</h2>
  <div class="titolare">
    <p><strong>Ragione sociale:</strong> <?= htmlspecialchars($orgName, ENT_QUOTES) ?></p>
    <p><strong>Sede legale:</strong> <?= htmlspecialchars($orgAddress, ENT_QUOTES) ?></p>
    <p><strong>Contatto privacy / DPO:</strong> <?= htmlspecialchars($orgEmail, ENT_QUOTES) ?></p>
    <p><strong>Giurisdizione:</strong> <?= htmlspecialchars($orgCountry, ENT_QUOTES) ?></p>
    <p><strong>Trattamento valutato:</strong> Moderazione automatizzata (AI + umana) dei commenti pubblicati sulla/e Pagina/e Facebook gestite tramite il sistema, incluse la gestione dei ban per recidiva e la profilazione comportamentale minima necessaria a tale scopo.</p>
  </div>

  <p class="body-text">
    Questa valutazione documenta per iscritto il test in tre passaggi richiesto dalle linee guida EDPB/WP29 prima di poter fondare un trattamento sull'art. 6(1)(f) GDPR (legittimo interesse): <strong>Purpose Test</strong> (esiste un interesse legittimo reale e specifico?), <strong>Necessity Test</strong> (il trattamento è necessario, o esiste un'alternativa meno invasiva?) e <strong>Balancing Test</strong> (l'interesse del Titolare prevale sui diritti e le aspettative ragionevoli dell'interessato?). È il documento a cui rimanda il §4 della privacy policy pubblica quando dichiara che "il Titolare ha condotto una valutazione di bilanciamento".
  </p>

  <!-- 2. Purpose test -->
  <h2>2. Purpose Test — esiste un interesse legittimo?</h2>
  <div class="test-card">
    <div class="tc-head">Interesse dichiarato <span class="verdict">Superato</span></div>
    <div class="tc-body">
      <p><strong>Interesse del Titolare:</strong> proteggere gli utenti della Pagina da spam, truffe, contenuti illeciti e abusi, e tutelare la reputazione e l'integrità editoriale della Pagina stessa. Non è un interesse generico ("migliorare il servizio"): è specifico (sicurezza e legalità dei commenti pubblicati), reale (il volume di commenti su una Pagina pubblica rende concreto il rischio di spam/abusi) e presente (il trattamento risponde a un bisogno operativo attuale, non ipotetico).</p>
      <p><strong>Interesse di terzi:</strong> anche gli altri utenti della Pagina hanno un interesse concorrente a non essere esposti a truffe, contenuti offensivi o disinformazione nei commenti che leggono.</p>
      <p><strong>Base legale alternativa considerata e scartata:</strong> il consenso (art. 6.1.a) non è praticabile — l'utente commenta su una Pagina pubblica senza un rapporto di registrazione con il Titolare, e un consenso "raccolto" da chi modera i propri stessi contenuti non sarebbe liberamente prestato. L'esecuzione di un contratto (art. 6.1.b) non si applica: non esiste un contratto tra il Titolare e il commentatore occasionale.</p>
    </div>
  </div>

  <!-- 3. Necessity test -->
  <h2>3. Necessity Test — il trattamento è necessario?</h2>
  <div class="test-card">
    <div class="tc-head">Necessità della pipeline AI <span class="verdict">Superato</span></div>
    <div class="tc-body">
      <p><strong>Perché non basta la sola moderazione umana:</strong> il volume di commenti su una Pagina pubblica rende impraticabile una revisione umana sistematica di ogni commento entro tempi utili a limitare il danno di contenuti illeciti in tempo reale (truffe, incitamento, diffamazione). Un ritardo di ore o giorni vanifica la finalità protettiva.</p>
      <p><strong>Perché non bastano semplici filtri per parole chiave:</strong> i filtri statici producono un tasso elevato di falsi positivi/negativi su linguaggio naturale, ironia, variazioni ortografiche — meno efficaci della classificazione contestuale di un modello linguistico e più invasivi in caso di falso positivo (nessuna motivazione, nessuna gradazione di confidenza).</p>
      <p><strong>Perché una pipeline a più stadi:</strong> solo le decisioni ad alta confidenza vengono applicate automaticamente (Livello 1 Haiku); i casi incerti sono rivalutati (Livello 2 Sonnet) o passati a un moderatore umano (Livello 3) — il trattamento automatizzato è quindi limitato ai casi in cui l'errore è meno probabile, non applicato indiscriminatamente.</p>
      <p><strong>Minimizzazione già in fase di progettazione:</strong> al fornitore AI esterno non vengono mai inviati nome reale, ID Facebook originale, URL profilo o dati di contatto — solo il testo del commento, uno pseudonimo non reversibile e segnali di rischio aggregati per fasce (vedi §5.2 privacy policy).</p>
    </div>
  </div>

  <!-- 4. Balancing test -->
  <h2>4. Balancing Test — l'interesse del Titolare prevale?</h2>

  <?php if ($isPreLaunch): ?>
  <div class="callout" style="margin-bottom:1.2rem">
    <strong>Sistema non ancora in produzione</strong>
    Questa valutazione viene condotta <em>prima</em> dell'avvio del trattamento, come richiede la metodologia stessa della LIA: non esistono ancora dati operativi (ban, appelli) da misurare, e non potrebbero comunque motivare la decisione che questo documento deve supportare. La soglia sotto è il parametro configurato che governerà il trattamento fin dal primo commento; il Balancing Test che segue si fonda sulle garanzie progettuali descritte, non su statistiche d'uso — che il documento riporterà automaticamente non appena disponibili, alla prossima rigenerazione.
  </div>
  <div class="stat-row">
    <div class="stat-cell">
      <div class="label">Soglia recidiva per ban</div>
      <div class="val"><?= (int) $recidivismLimit ?> violazioni</div>
    </div>
  </div>
  <?php else: ?>
  <div class="stat-row">
    <div class="stat-cell">
      <div class="label">Soglia recidiva per ban</div>
      <div class="val"><?= (int) $recidivismLimit ?> violazioni</div>
    </div>
    <div class="stat-cell">
      <div class="label">Ban attivi ora</div>
      <div class="val"><?= number_format((int) $totBans) ?></div>
    </div>
    <div class="stat-cell">
      <div class="label">Appelli ricevuti</div>
      <div class="val"><?= number_format((int) $totAppeals) ?></div>
    </div>
    <div class="stat-cell">
      <div class="label">Appelli accolti</div>
      <div class="val"><?= number_format((int) $appealsAccept) ?><?= $totAppeals > 0 ? ' (' . round($appealsAccept / $totAppeals * 100) . '%)' : '' ?></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="test-card">
    <div class="tc-head">Impatto sull'interessato vs. misure di mitigazione <span class="verdict">Superato</span></div>
    <div class="tc-body">
      <p><strong>Aspettativa ragionevole dell'interessato:</strong> chi commenta pubblicamente su una Pagina Facebook aziendale/editoriale si aspetta ragionevolmente che i commenti siano moderati secondo le regole della Pagina — non è un trattamento a sorpresa. Le regole di moderazione attive sono pubblicate senza autenticazione (§5.5 privacy policy).</p>
      <p><strong>Impatto potenziale sull'interessato:</strong> nascondimento temporaneo di un commento; in caso di recidiva, sospensione temporanea (mai permanente — nessun "ban definitivo" esiste nel sistema) dalla possibilità di commentare sulla Pagina. Nessun impatto extra-piattaforma (nessuna condivisione con terzi, nessuna conseguenza legale automatica).</p>
      <p><strong>Misure che riducono l'impatto (le stesse citate come garanzie al §5.4 della privacy policy):</strong></p>
      <ul class="measures">
        <li><strong>Pseudonimizzazione:</strong> il fornitore AI non riceve mai l'identità reale dell'utente.</li>
        <li><strong>Nessun ban alla prima violazione:</strong> serve un pattern di recidiva (soglia configurabile, attualmente <?= (int) $recidivismLimit ?> violazioni) prima di qualsiasi sospensione.</li>
        <li><strong>Ban sempre temporaneo:</strong> durata crescente ma finita (livello 1: <?= (int) $banCfg['hours_1'] ?>h, livello 2: <?= (int) $banCfg['days_2'] ?> giorni, livello 3+: <?= (int) $banCfg['days_3'] ?> giorni) — mai irreversibile per decisione automatica.</li>
        <li><strong>Blind review:</strong> il moderatore umano che rivede i casi incerti non vede mai il nome reale Facebook, riducendo il rischio di bias.</li>
        <li><strong>Appello sempre disponibile:</strong> ogni ban o nascondimento è contestabile ex-post; un umano rivede la contestazione<?= $isPreLaunch ? '' : ' (vedi statistiche sopra)' ?>.</li>
        <li><strong>Diritto di opposizione:</strong> l'interessato può opporsi al trattamento fondato sul legittimo interesse in qualsiasi momento (art. 21 GDPR, richiamato al §8 privacy policy).</li>
      </ul>
      <p><strong>Conclusione del bilanciamento:</strong> a fronte di un interesse legittimo reale e circoscritto, con misure di minimizzazione, reversibilità e contestabilità concrete, l'impatto residuo sull'interessato è proporzionato. L'interesse del Titolare a mantenere una Pagina sicura e legale prevale, salvo l'esercizio del diritto di opposizione caso per caso.</p>
    </div>
  </div>

  <div class="callout">
    <strong>Nota sui minori (Recital 38 GDPR)</strong>
    Una Pagina Facebook pubblica non ha modo di verificare l'età di chi commenta: il sistema non tratta intenzionalmente dati di minori e non li identifica come categoria separata (vedi §11 privacy policy), ma non può escluderne la presenza tra i commentatori. Il Recital 38 GDPR richiede che, quando gli interessati includono potenzialmente minori, il bilanciamento di cui sopra pesi a favore di tutele aggiuntive piuttosto che presumere che il legittimo interesse prevalga comunque. Le misure elencate sopra (nessun ban alla prima violazione, ban sempre temporaneo, blind review, appello sempre disponibile) sono quindi da intendersi anche come la risposta a questo requisito, non come misure generiche: sono le garanzie che rendono sostenibile l'uso del legittimo interesse anche quando l'interessato potrebbe essere minorenne. Non sono un motivo per trattare dati di minori in modo diverso, ma la ragione per cui il Titolare non ha bisogno di verificare l'età per poter bilanciare correttamente.
  </div>

  <!-- 5. Conclusione -->
  <h2>5. Conclusione</h2>
  <p class="body-text">
    I tre test sono superati: il legittimo interesse dichiarato al §4 della privacy policy è specifico e reale (Purpose Test), il trattamento tramite pipeline AI/umana è necessario e proporzionato rispetto ad alternative meno efficaci (Necessity Test), e le misure di minimizzazione, reversibilità e contestabilità riducono l'impatto sull'interessato a un livello che l'interesse del Titolare può ragionevolmente prevalere (Balancing Test), inclusa la possibile presenza di minori tra i commentatori. Questa valutazione va aggiornata in caso di modifiche sostanziali alla pipeline di moderazione, alla soglia di recidiva o al fornitore AI.
  </p>

  <!-- Signature -->
  <div class="signature">
    <div class="sig-head">Approvazione</div>
    <div class="sig-body">
      <div class="sig-cell">
        <div class="sig-label">Titolare del trattamento</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Nome, ruolo, data</div>
      </div>
      <div class="sig-cell">
        <div class="sig-label">DPO / consulente privacy (se designato)</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Nome, ruolo, data</div>
      </div>
    </div>
  </div>

</div><!-- /lang-section IT -->

<hr class="divider">

<!-- ═══════════════════════════════════════════════════════════════
     ENGLISH SECTION
     ═══════════════════════════════════════════════════════════════ -->
<div class="lang-section" lang="en">

  <!-- Document header -->
  <div class="doc-header">
    <div class="doc-title">Legitimate Interest Assessment (LIA)</div>
    <div class="doc-sub">Balancing test — supporting the legal basis under Art. 6(1)(f) Reg. (EU) 2016/679 (GDPR)</div>
    <div class="doc-meta">
      <span><strong>Controller:</strong> <?= htmlspecialchars($orgName, ENT_QUOTES) ?></span>
      <span><strong>System:</strong> Social Moderation Hub v<?= htmlspecialchars($appVersion, ENT_QUOTES) ?></span>
      <span><strong>Drafted on:</strong> <?= htmlspecialchars($today, ENT_QUOTES) ?></span>
      <span><strong>Document version:</strong> 1.0</span>
    </div>
  </div>

  <!-- 1. Controller -->
  <h2>1. Data Controller</h2>
  <div class="titolare">
    <p><strong>Legal name:</strong> <?= htmlspecialchars($orgName, ENT_QUOTES) ?></p>
    <p><strong>Registered address:</strong> <?= htmlspecialchars($orgAddress, ENT_QUOTES) ?></p>
    <p><strong>Privacy / DPO contact:</strong> <?= htmlspecialchars($orgEmail, ENT_QUOTES) ?></p>
    <p><strong>Jurisdiction:</strong> <?= htmlspecialchars($orgCountry, ENT_QUOTES) ?></p>
    <p><strong>Processing assessed:</strong> Automated (AI + human) moderation of comments published on the Facebook Page(s) managed through the system, including the management of recidivism-based bans and the minimal behavioural profiling necessary for that purpose.</p>
  </div>

  <p class="body-text">
    This assessment records in writing the three-step test required by the EDPB/WP29 guidelines before a processing activity can be based on Art. 6(1)(f) GDPR (legitimate interest): the <strong>Purpose Test</strong> (does a real and specific legitimate interest exist?), the <strong>Necessity Test</strong> (is the processing necessary, or is there a less invasive alternative?), and the <strong>Balancing Test</strong> (does the Controller's interest override the data subject's rights and reasonable expectations?). This is the document referenced by §4 of the public privacy policy when it states that "the Controller has carried out a balancing assessment".
  </p>

  <!-- 2. Purpose test -->
  <h2>2. Purpose Test — does a legitimate interest exist?</h2>
  <div class="test-card">
    <div class="tc-head">Declared interest <span class="verdict">Passed</span></div>
    <div class="tc-body">
      <p><strong>Controller's interest:</strong> protecting the Page's users from spam, scams, unlawful content and abuse, and safeguarding the Page's reputation and editorial integrity. This is not a generic interest ("improving the service"): it is specific (the safety and lawfulness of published comments), real (the volume of comments on a public Page makes the risk of spam/abuse concrete) and present (the processing responds to a current operational need, not a hypothetical one).</p>
      <p><strong>Interests of third parties:</strong> other users of the Page also have a competing interest in not being exposed to scams, offensive content or disinformation in the comments they read.</p>
      <p><strong>Alternative legal basis considered and discarded:</strong> consent (Art. 6.1.a) is not practicable — the user comments on a public Page without any registration relationship with the Controller, and consent "collected" by whoever moderates their own content would not be freely given. Performance of a contract (Art. 6.1.b) does not apply: no contract exists between the Controller and the occasional commenter.</p>
    </div>
  </div>

  <!-- 3. Necessity test -->
  <h2>3. Necessity Test — is the processing necessary?</h2>
  <div class="test-card">
    <div class="tc-head">Necessity of the AI pipeline <span class="verdict">Passed</span></div>
    <div class="tc-body">
      <p><strong>Why human moderation alone is not enough:</strong> the volume of comments on a public Page makes systematic human review of every comment impractical within a timeframe useful to limit the harm of unlawful content in real time (scams, incitement, defamation). A delay of hours or days defeats the protective purpose.</p>
      <p><strong>Why simple keyword filters are not enough:</strong> static filters produce a high rate of false positives/negatives on natural language, irony and spelling variations — less effective than the contextual classification of a language model, and more invasive in the event of a false positive (no reasoning, no confidence gradation).</p>
      <p><strong>Why a multi-stage pipeline:</strong> only high-confidence decisions are applied automatically (Tier 1 Haiku); uncertain cases are re-evaluated (Tier 2 Sonnet) or passed to a human moderator (Tier 3) — automated processing is therefore limited to the cases where error is least likely, not applied indiscriminately.</p>
      <p><strong>Minimisation already built into the design:</strong> the external AI provider is never sent the user's real name, original Facebook ID, profile URL or contact details — only the comment text, a non-reversible pseudonym and risk signals aggregated into bands (see §5.2 of the privacy policy).</p>
    </div>
  </div>

  <!-- 4. Balancing test -->
  <h2>4. Balancing Test — does the Controller's interest override?</h2>

  <?php if ($isPreLaunch): ?>
  <div class="callout" style="margin-bottom:1.2rem">
    <strong>System not yet in production</strong>
    This assessment is carried out <em>before</em> processing begins, as the LIA methodology itself requires: there is no operational data (bans, appeals) to measure yet, and none could meaningfully inform the decision this document is meant to support anyway. The threshold below is the configured parameter that will govern processing from the first comment onward; the Balancing Test that follows rests on the designed safeguards described, not on usage statistics — which the document will report automatically once available, at the next regeneration.
  </div>
  <div class="stat-row">
    <div class="stat-cell">
      <div class="label">Recidivism threshold for ban</div>
      <div class="val"><?= (int) $recidivismLimit ?> violations</div>
    </div>
  </div>
  <?php else: ?>
  <div class="stat-row">
    <div class="stat-cell">
      <div class="label">Recidivism threshold for ban</div>
      <div class="val"><?= (int) $recidivismLimit ?> violations</div>
    </div>
    <div class="stat-cell">
      <div class="label">Active bans now</div>
      <div class="val"><?= number_format((int) $totBans) ?></div>
    </div>
    <div class="stat-cell">
      <div class="label">Appeals received</div>
      <div class="val"><?= number_format((int) $totAppeals) ?></div>
    </div>
    <div class="stat-cell">
      <div class="label">Appeals upheld</div>
      <div class="val"><?= number_format((int) $appealsAccept) ?><?= $totAppeals > 0 ? ' (' . round($appealsAccept / $totAppeals * 100) . '%)' : '' ?></div>
    </div>
  </div>
  <?php endif; ?>

  <div class="test-card">
    <div class="tc-head">Impact on the data subject vs. mitigation measures <span class="verdict">Passed</span></div>
    <div class="tc-body">
      <p><strong>Data subject's reasonable expectation:</strong> anyone commenting publicly on a corporate/editorial Facebook Page reasonably expects comments to be moderated according to the Page's rules — this is not a surprising processing activity. The active moderation rules are published without authentication (§5.5 of the privacy policy).</p>
      <p><strong>Potential impact on the data subject:</strong> temporary hiding of a comment; in case of recidivism, a temporary suspension (never permanent — no "definitive ban" exists in the system) of the ability to comment on the Page. No impact beyond the platform (no sharing with third parties, no automatic legal consequence).</p>
      <p><strong>Measures that reduce the impact (the same safeguards cited in §5.4 of the privacy policy):</strong></p>
      <ul class="measures">
        <li><strong>Pseudonymisation:</strong> the AI provider never receives the user's real identity.</li>
        <li><strong>No ban on first violation:</strong> a recidivism pattern is required (configurable threshold, currently <?= (int) $recidivismLimit ?> violations) before any suspension.</li>
        <li><strong>Ban always temporary:</strong> increasing but finite duration (tier 1: <?= (int) $banCfg['hours_1'] ?>h, tier 2: <?= (int) $banCfg['days_2'] ?> days, tier 3+: <?= (int) $banCfg['days_3'] ?> days) — never irreversible by automated decision.</li>
        <li><strong>Blind review:</strong> the human moderator reviewing uncertain cases never sees the real Facebook name, reducing the risk of bias.</li>
        <li><strong>Appeal always available:</strong> every ban or hiding is contestable after the fact; a human reviews the challenge<?= $isPreLaunch ? '' : ' (see statistics above)' ?>.</li>
        <li><strong>Right to object:</strong> the data subject may object to processing based on legitimate interest at any time (Art. 21 GDPR, referenced in §8 of the privacy policy).</li>
      </ul>
      <p><strong>Balancing conclusion:</strong> given a real and circumscribed legitimate interest, with concrete minimisation, reversibility and contestability measures, the residual impact on the data subject is proportionate. The Controller's interest in keeping the Page safe and lawful prevails, subject to the case-by-case exercise of the right to object.</p>
    </div>
  </div>

  <div class="callout">
    <strong>Note on minors (Recital 38 GDPR)</strong>
    A public Facebook Page has no way to verify the age of who comments: the system does not intentionally process minors' data and does not identify them as a separate category (see §11 of the privacy policy), but it cannot rule out their presence among commenters. Recital 38 GDPR requires that, when data subjects potentially include minors, the balancing test above should weigh in favour of additional safeguards rather than presuming that the legitimate interest prevails regardless. The measures listed above (no ban on first violation, ban always temporary, blind review, appeal always available) are therefore also to be understood as the response to this requirement, not as generic measures: they are the safeguards that make reliance on legitimate interest sustainable even when the data subject may be a minor. They are not a reason to treat minors' data differently, but the reason why the Controller does not need to verify age in order to balance correctly.
  </div>

  <!-- 5. Conclusion -->
  <h2>5. Conclusion</h2>
  <p class="body-text">
    All three tests are passed: the legitimate interest declared in §4 of the privacy policy is specific and real (Purpose Test), processing through the AI/human pipeline is necessary and proportionate compared to less effective alternatives (Necessity Test), and the minimisation, reversibility and contestability measures reduce the impact on the data subject to a level the Controller's interest can reasonably override (Balancing Test), including the possible presence of minors among commenters. This assessment must be updated in the event of substantial changes to the moderation pipeline, the recidivism threshold or the AI provider.
  </p>

  <!-- Signature -->
  <div class="signature">
    <div class="sig-head">Approval</div>
    <div class="sig-body">
      <div class="sig-cell">
        <div class="sig-label">Data Controller</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Name, role, date</div>
      </div>
      <div class="sig-cell">
        <div class="sig-label">DPO / privacy consultant (if appointed)</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Name, role, date</div>
      </div>
    </div>
  </div>

</div><!-- /lang-section EN -->

<div class="doc-footer">
  <span>
    Documento generato automaticamente dal Social Moderation Hub — <?= htmlspecialchars($appUrl, ENT_QUOTES) ?> — non sostituisce la consulenza di un legale o DPO qualificato.<br>
    Document automatically generated by Social Moderation Hub — <?= htmlspecialchars($appUrl, ENT_QUOTES) ?> — does not replace the advice of a qualified lawyer or DPO.
  </span>
  <span class="no-print" style="display:block;margin-top:.6rem">
    <a href="javascript:window.print()" style="color:#555;text-decoration:none">🖨 Stampa / Salva PDF</a>
  </span>
</div>

</div>
</body>
</html>
