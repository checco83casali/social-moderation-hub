<?php
// public/lia.php
// Legitimate Interest Assessment (LIA) — valutazione di bilanciamento degli
// interessi a supporto della base giuridica art. 6.1.f GDPR.
// Generato automaticamente dal sistema. Non modificare manualmente.
// Chiamato da ModerationController::exportLia() con extract($vars).
?><!DOCTYPE html>
<html lang="it">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>LIA – <?= htmlspecialchars($orgName, ENT_QUOTES) ?></title>
  <style>
    *, *::before, *::after { box-sizing: border-box; margin: 0; padding: 0; }
    body {
      font-family: 'Helvetica Neue', Arial, sans-serif;
      font-size: 13px; line-height: 1.6; color: #1a1a2e;
      background: #fff; padding: 2.5rem 2rem 4rem;
    }
    .page { max-width: 960px; margin: 0 auto; }

    /* Header */
    .doc-header { border-bottom: 3px solid #1a1a2e; padding-bottom: 1.2rem; margin-bottom: 2rem; }
    .doc-title  { font-size: 1.4rem; font-weight: 700; letter-spacing: -.3px; margin-bottom: .3rem; }
    .doc-sub    { font-size: 12px; color: #555; }
    .doc-meta   { margin-top: 1rem; display: flex; gap: 2rem; flex-wrap: wrap; }
    .doc-meta span { font-size: 12px; color: #555; }
    .doc-meta strong { color: #1a1a2e; }

    /* Sections */
    h2 { font-size: .85rem; font-weight: 700; text-transform: uppercase;
          letter-spacing: .8px; color: #1a1a2e; margin: 2.5rem 0 1rem;
          padding-bottom: .4rem; border-bottom: 1px solid #ddd; }
    h3 { font-size: .8rem; font-weight: 700; text-transform: uppercase;
          letter-spacing: .5px; color: #555; margin: 1.5rem 0 .5rem; }
    p.body-text { font-size: 12.5px; color: #333; margin-bottom: .8rem; }

    /* Titolare box */
    .titolare { background: #f5f7ff; border: 1px solid #c8d0f0; border-radius: 6px;
                 padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-size: 13px; }
    .titolare p { margin-bottom: .3rem; }
    .titolare strong { display: inline-block; min-width: 160px; color: #333; }

    /* Test card */
    .test-card { border: 1px solid #dde; border-radius: 6px; margin-bottom: 1.5rem; overflow: hidden; }
    .tc-head { background: #1a1a2e; color: #fff; padding: .7rem 1.1rem; font-size: 13.5px; font-weight: 600;
                display: flex; justify-content: space-between; align-items: center; }
    .tc-head .verdict { font-size: 10.5px; font-weight: 700; text-transform: uppercase;
                          letter-spacing: .4px; background: #2ecc71; color: #fff; padding: 2px 10px; border-radius: 10px; }
    .tc-body { padding: 1rem 1.1rem; font-size: 12.5px; }
    .tc-body p { margin-bottom: .7rem; }
    .tc-body p:last-child { margin-bottom: 0; }

    /* Stat row */
    .stat-row { display: grid; grid-template-columns: repeat(auto-fit, minmax(150px, 1fr));
                 gap: 0; border: 1px solid #dde; border-radius: 6px; overflow: hidden; margin: .8rem 0 1.2rem; }
    .stat-cell { padding: .7rem 1rem; border-right: 1px solid #eef; }
    .stat-cell:last-child { border-right: none; }
    .stat-cell .label { font-size: 10px; font-weight: 700; text-transform: uppercase;
                          letter-spacing: .5px; color: #888; margin-bottom: .25rem; }
    .stat-cell .val { font-size: 1.15rem; font-weight: 700; color: #1a1a2e; }

    /* Measure list */
    ul.measures { padding-left: 1.4rem; margin: .4rem 0; }
    ul.measures li { margin-bottom: .3rem; font-size: 12.5px; }

    /* Recital 38 callout */
    .callout { border-left: 4px solid #f59e0b; background: #fff8e1; border-radius: 0 6px 6px 0;
                padding: .8rem 1rem; margin: 1rem 0; font-size: 12.5px; }
    .callout strong { display: block; margin-bottom: .3rem; }

    /* Signature block */
    .signature { border: 1px solid #dde; border-radius: 6px; margin-bottom: 1.5rem; }
    .sig-head { background: #f5f5f5; padding: .55rem 1rem; font-size: 11px; font-weight: 700;
                 text-transform: uppercase; letter-spacing: .4px; color: #555;
                 border-bottom: 1px solid #dde; }
    .sig-body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
    .sig-cell { padding: 1.5rem 1rem; border-right: 1px solid #dde; font-size: 12px; }
    .sig-cell:last-child { border-right: none; }
    .sig-cell .sig-label { font-size: 10.5px; color: #888; margin-bottom: .3rem; }
    .sig-cell .sig-line  { border-bottom: 1px solid #aaa; height: 28px; margin-bottom: .5rem; }
    .sig-cell .sig-sub   { font-size: 10.5px; color: #bbb; }

    /* Footer */
    .doc-footer { margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #eee;
                   font-size: 11px; color: #aaa; text-align: center; }

    @media print {
      body { padding: 1rem; }
      .doc-footer { position: fixed; bottom: 0; width: 100%; }
    }
  </style>
</head>
<body>
<div class="page">

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
        <li><strong>Appello sempre disponibile:</strong> ogni ban o nascondimento è contestabile ex-post; un umano rivede la contestazione (vedi statistiche sopra).</li>
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

  <div class="doc-footer">
    Documento generato automaticamente dal Social Moderation Hub — <?= htmlspecialchars($appUrl, ENT_QUOTES) ?> — non sostituisce la consulenza di un legale o DPO qualificato.
  </div>

</div>
</body>
</html>
