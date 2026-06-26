<?php
// public/dpia.php
// Valutazione d'Impatto sulla Protezione dei Dati (DPIA) — art. 35 Reg. UE 2016/679 (GDPR)
// Generato automaticamente dal sistema. Non modificare manualmente.
// Chiamato da ModerationController::exportDpia() con extract($vars).
?><!DOCTYPE html>
<html lang="it">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DPIA – <?= htmlspecialchars($orgName, ENT_QUOTES) ?></title>
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

    /* Titolare box */
    .titolare { background: #f5f7ff; border: 1px solid #c8d0f0; border-radius: 6px;
                 padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-size: 13px; }
    .titolare p { margin-bottom: .3rem; }
    .titolare strong { display: inline-block; min-width: 160px; color: #333; }

    /* System description card */
    .system-card { border: 1px solid #dde; border-radius: 6px; margin-bottom: 1.5rem; overflow: hidden; }
    .sc-head { background: #1a1a2e; color: #fff; padding: .7rem 1.1rem; font-size: 13.5px; font-weight: 600; }
    .sc-body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
    .sc-cell { padding: .75rem 1.1rem; border-bottom: 1px solid #eef; border-right: 1px solid #eef; font-size: 12.5px; }
    .sc-cell:nth-child(2n) { border-right: none; }
    .sc-cell.full { grid-column: 1 / -1; border-right: none; }
    .sc-cell .label { font-size: 10px; font-weight: 700; text-transform: uppercase;
                       letter-spacing: .5px; color: #888; margin-bottom: .25rem; }
    .sc-cell .val { color: #1a1a2e; }

    /* Risk table */
    table { width: 100%; border-collapse: collapse; margin-bottom: 1.5rem; font-size: 12px; }
    th { background: #f0f0f8; text-align: left; padding: .55rem .8rem;
          font-size: 11px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px;
          color: #444; border: 1px solid #dde; }
    td { padding: .6rem .8rem; border: 1px solid #dde; vertical-align: top; }
    tr:nth-child(even) td { background: #fafafe; }

    /* Risk badge */
    .risk { display: inline-block; padding: 2px 8px; border-radius: 10px;
             font-size: 10.5px; font-weight: 700; text-transform: uppercase; letter-spacing: .4px; }
    .risk-high   { background: #ffeaea; color: #c0392b; }
    .risk-medium { background: #fff3e0; color: #b35900; }
    .risk-low    { background: #e8f5e9; color: #1b7a34; }
    .risk-residual { background: #f0f4ff; color: #3a5299; }

    /* Measure list */
    ul.measures { padding-left: 1.4rem; margin: .4rem 0; }
    ul.measures li { margin-bottom: .3rem; font-size: 12.5px; }

    /* Signature block */
    .signature { border: 1px solid #dde; border-radius: 6px; margin-bottom: 1.5rem; }
    .sig-head { background: #f5f5f5; padding: .55rem 1rem; font-size: 11px; font-weight: 700;
                 text-transform: uppercase; letter-spacing: .4px; color: #555;
                 border-bottom: 1px solid #dde; }
    .sig-body { display: grid; grid-template-columns: 1fr 1fr 1fr; gap: 0; }
    .sig-cell { padding: 1.5rem 1rem; border-right: 1px solid #dde; font-size: 12px; }
    .sig-cell:last-child { border-right: none; }
    .sig-cell .sig-label { font-size: 10.5px; color: #888; margin-bottom: .3rem; }
    .sig-cell .sig-line  { border-bottom: 1px solid #aaa; height: 28px; margin-bottom: .5rem; }
    .sig-cell .sig-sub   { font-size: 10.5px; color: #bbb; }

    /* DPO notes */
    .dpo-notes { border: 1px dashed #c8d0f0; border-radius: 6px; padding: 1rem 1.25rem;
                  background: #fafcff; min-height: 90px; }
    .dpo-notes .placeholder { color: #bbb; font-size: 12px; font-style: italic; }

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
    <div class="doc-title">Valutazione d'Impatto sulla Protezione dei Dati (DPIA)</div>
    <div class="doc-sub">Data Protection Impact Assessment — art. 35 Reg. UE 2016/679 (GDPR)</div>
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
    <p><strong>Autorità di controllo:</strong> <?= htmlspecialchars($supervisory, ENT_QUOTES) ?></p>
    <p><strong>URL sistema:</strong> <?= htmlspecialchars($appUrl, ENT_QUOTES) ?></p>
  </div>

  <!-- 2. Descrizione del trattamento -->
  <h2>2. Descrizione del trattamento e del sistema</h2>
  <div class="system-card">
    <div class="sc-head">Social Moderation Hub — pipeline di moderazione automatizzata</div>
    <div class="sc-body">
      <div class="sc-cell">
        <div class="label">Finalità</div>
        <div class="val">Moderazione automatizzata dei commenti pubblicati sulla/e Pagina/e Facebook collegata/e, al fine di rilevare contenuti che violino la politica editoriale del titolare (spam, odio, truffe, disinformazione, ecc.) e proteggere gli utenti da contenuti potenzialmente illegali.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Base giuridica</div>
        <div class="val">Art. 6(1)(f) GDPR — legittimo interesse del titolare a garantire la sicurezza e la legalità della propria presenza online e a proteggere la community da contenuti dannosi. L'interesse prevalente è verificato nel test di bilanciamento allegato (sez. 4).</div>
      </div>
      <div class="sc-cell">
        <div class="label">Tipologie di dati trattati</div>
        <div class="val">ID utente Facebook (pseudonimo), testo del commento, timestamp, ID post/pagina; conteggi interni di violazioni; flag di ban; log delle decisioni AI (modello, confidenza, latenza, motivazione testuale).</div>
      </div>
      <div class="sc-cell">
        <div class="label">Categorie di interessati</div>
        <div class="val">Utenti Facebook che commentano sui post della/e Pagina/e collegata/e. Non vengono trattate categorie particolari di dati ex art. 9 GDPR, salvo che il contenuto del commento li contenga incidentalmente.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Pipeline di elaborazione</div>
        <div class="val">Webhook Meta → Claude Haiku (primo stadio) → Claude Sonnet (escalation) → Coda revisione umana. I dati sono inviati ad Anthropic tramite API per la sola inferenza; nessun fine-tuning o training sui dati degli interessati.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Trasferimenti extra-UE</div>
        <div class="val">Anthropic PBC (USA) — invio del testo del commento per inferenza AI. Base: clausole contrattuali tipo (SCCs) o accordo DPA specifico. Meta Platforms (USA/Irlanda) — dati già presenti sulla piattaforma di origine.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Conservazione</div>
        <div class="val">Dati operativi conservati per il periodo configurato (finestra GDPR impostata nel sistema). Dopo la scadenza: anonimizzazione automatica (campi PII sostituiti con hash/NULL, colonne statistiche conservate). Attualmente: <?= (int)$retentionDays ?> giorni.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Statistiche correnti</div>
        <div class="val">
          Commenti: <?= number_format((int)$totComments) ?> &nbsp;·&nbsp;
          Utenti: <?= number_format((int)$totUsers) ?> &nbsp;·&nbsp;
          Ban attivi: <?= number_format((int)$totBans) ?> &nbsp;·&nbsp;
          Pagine collegate: <?= number_format((int)$totPages) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Necessità e proporzionalità -->
  <h2>3. Necessità e proporzionalità</h2>
  <table>
    <thead>
      <tr><th style="width:30%">Criterio</th><th>Valutazione</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Limitazione della finalità</strong></td>
        <td>I dati sono trattati esclusivamente per finalità di moderazione editoriale sulla Pagina Facebook del titolare. Non vengono usati per profilazione commerciale, pubblicità o vendita a terzi.</td>
      </tr>
      <tr>
        <td><strong>Minimizzazione dei dati</strong></td>
        <td>Vengono conservati solo i dati strettamente necessari: ID pseudonimizzato, testo del commento, timestamp e metriche di moderazione. Non vengono acquisiti indirizzo, e-mail o altri dati identificativi dell'utente Facebook.</td>
      </tr>
      <tr>
        <td><strong>Limitazione della conservazione</strong></td>
        <td>Anonimizzazione automatica dopo <?= (int)$retentionDays ?> giorni tramite cron notturno. Il sistema avvisa se il cron non viene eseguito da più di 48 ore.</td>
      </tr>
      <tr>
        <td><strong>Accuratezza</strong></td>
        <td>Le decisioni AI sono associate a un punteggio di confidenza. Sotto soglia, il commento è escalato alla revisione umana. Le decisioni umane sovrascrivono quelle AI e vengono loggate separatamente.</td>
      </tr>
      <tr>
        <td><strong>Trasparenza</strong></td>
        <td>La Privacy Policy pubblica (<code><?= htmlspecialchars($appUrl, ENT_QUOTES) ?>/privacy</code>) informa gli interessati dell'uso di AI per la moderazione, del legittimo interesse applicato e dei loro diritti (accesso, cancellazione, opposizione).</td>
      </tr>
      <tr>
        <td><strong>Diritti degli interessati</strong></td>
        <td>Workflow di appello integrato: i commenti nascosti includono un link di appello firmato crittograficamente (URL valido 30 giorni) con cui l'utente può contestare la decisione. I moderatori esaminano e rispondono.</td>
      </tr>
    </tbody>
  </table>

  <!-- 4. Fattori di rischio -->
  <h2>4. Fattori che determinano l'obbligo di DPIA (art. 35(3) + linee guida WP248)</h2>
  <table>
    <thead>
      <tr><th style="width:35%">Criterio WP248</th><th style="width:12%">Presente</th><th>Dettaglio</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Valutazione / scoring</td>
        <td style="text-align:center">✅</td>
        <td>Il sistema assegna un punteggio di rischio a ogni commento e traccia uno storico di violazioni per utente (recidivismo).</td>
      </tr>
      <tr>
        <td>Decisioni automatizzate con effetti significativi</td>
        <td style="text-align:center">✅</td>
        <td>Una decisione AI può portare a nascondere o rimuovere un commento in modo completamente automatico (senza intervento umano) quando la confidenza supera la soglia configurata.</td>
      </tr>
      <tr>
        <td>Monitoraggio sistematico</td>
        <td style="text-align:center">✅</td>
        <td>Ogni commento pubblicato sulla Pagina viene acquisito e analizzato in tempo reale tramite webhook Meta.</td>
      </tr>
      <tr>
        <td>Dati su larga scala</td>
        <td style="text-align:center">⚠️</td>
        <td>La scala dipende dall'audience della Pagina. Con audience elevata il volume può essere significativo. Il titolare valuta se la propria installazione supera la soglia di "larga scala".</td>
      </tr>
      <tr>
        <td>Dati di categorie particolari (art. 9)</td>
        <td style="text-align:center">⚠️</td>
        <td>Non trattati intenzionalmente, ma i commenti degli utenti possono contenere opinioni politiche, religiose o dati sulla salute. Il sistema non estrae né conserva tali dati in campi dedicati.</td>
      </tr>
      <tr>
        <td>Accoppiamento / combinazione di dataset</td>
        <td style="text-align:center">✅</td>
        <td>Lo storico di violazioni per utente (contatore recidivismo, ban attivi) viene combinato con il contenuto del commento corrente per arricchire il contesto inviato a Claude.</td>
      </tr>
      <tr>
        <td>Vulnerabilità degli interessati</td>
        <td style="text-align:center">⚠️</td>
        <td>La base di utenti Facebook può includere minori. Il sistema non tratta dati di minori intenzionalmente, ma non può escluderli.</td>
      </tr>
      <tr>
        <td>Uso innovativo o applicazione di nuove soluzioni tecnologiche</td>
        <td style="text-align:center">✅</td>
        <td>Utilizzo di Large Language Model (Claude di Anthropic) per prendere decisioni editoriali in modo automatizzato.</td>
      </tr>
    </tbody>
  </table>
  <p style="font-size:12px;color:#777;margin-top:-.5rem;margin-bottom:1.5rem">
    <strong>Conclusione:</strong> Sono presenti almeno 3 criteri ad alto rischio (scoring, decisioni automatizzate, monitoraggio sistematico), soglia che rende la DPIA obbligatoria ai sensi dell'art. 35(1) e delle linee guida WP248 dell'EDPB.
  </p>

  <!-- 5. Identificazione dei rischi -->
  <h2>5. Identificazione e valutazione dei rischi</h2>
  <table>
    <thead>
      <tr>
        <th style="width:22%">Rischio</th>
        <th style="width:10%">Livello iniziale</th>
        <th style="width:38%">Scenario</th>
        <th style="width:10%">Livello residuo</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>R1 — Falso positivo: oscuramento di contenuto legittimo</strong></td>
        <td><span class="risk risk-high">Alto</span></td>
        <td>Il modello AI classifica erroneamente un commento legittimo come violazione e lo nasconde automaticamente, limitando la libertà di espressione dell'utente.</td>
        <td><span class="risk risk-residual">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R2 — Escalation errata del ban (recidivismo)</strong></td>
        <td><span class="risk risk-high">Alto</span></td>
        <td>Un utente viene bannato temporaneamente o permanentemente a causa di falsi positivi accumulati nel tempo, senza aver effettivamente violato le regole.</td>
        <td><span class="risk risk-residual">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R3 — Violazione dati / data breach</strong></td>
        <td><span class="risk risk-high">Alto</span></td>
        <td>Accesso non autorizzato al database (credenziali compromesse, SQL injection, server compromise, insider threat, backup in chiaro). Dati esposti: commenti, pseudonimi, storico violazioni, log AI, token di appello, account amministratori. Obbligo di notifica artt. 33–34 GDPR.</td>
        <td><span class="risk risk-low">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R4 — Trasferimento dati a Anthropic</strong></td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Il testo del commento (potenzialmente contenente dati personali) viene inviato ad Anthropic (USA) per l'inferenza AI.</td>
        <td><span class="risk risk-residual">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R5 — Bias del modello AI e del revisore umano</strong></td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Il modello AI può mostrare bias sistematici. I moderatori umani possono introdurre pregiudizi basati sull'identità (nome, etnia percepita, genere percepito) dell'autore del commento.</td>
        <td><span class="risk risk-low">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R6 — Dipendenza da servizio terzo</strong></td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Indisponibilità dell'API Anthropic o variazioni nei modelli (deprecazione, cambio di comportamento) che alterano la qualità della moderazione senza preavviso.</td>
        <td><span class="risk risk-residual">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R7 — Conservazione eccessiva</strong></td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Mancata esecuzione del cron di anonimizzazione porta a conservare dati PII oltre il periodo configurato.</td>
        <td><span class="risk risk-low">Basso</span></td>
      </tr>
      <tr>
        <td><strong>R8 — Profilazione non dichiarata</strong></td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Il contatore recidivismo crea un profilo comportamentale dell'utente nel tempo, non esplicitamente dichiarato nella privacy policy pubblica come "profilazione".</td>
        <td><span class="risk risk-residual">Basso</span></td>
      </tr>
    </tbody>
  </table>

  <!-- 6. Misure di mitigazione -->
  <h2>6. Misure tecniche e organizzative di mitigazione</h2>

  <h3>R1 + R2 — Falsi positivi e ban ingiusto</h3>
  <ul class="measures">
    <li><strong>Pipeline a due stadi + soglia:</strong> Haiku decide solo se supera la soglia di confidenza; sotto soglia, Sonnet rivaluta. Sotto la soglia Sonnet, il commento va in coda umana senza alcuna azione automatica.</li>
    <li><strong>Appello firmato:</strong> ogni commento nascosto automaticamente include un link di appello crittograficamente firmato (HMAC-SHA256, scadenza 30 giorni) che l'utente può usare per contestare la decisione; un moderatore umano rivede e può ripristinare il commento.</li>
    <li><strong>Ban progressivo:</strong> il ban automatico richiede più violazioni confermate (soglia configurabile in <code>RECIDIVISM_COMMENT_BAN_LIMIT</code>). I moderatori possono revocare manualmente qualsiasi ban dal dashboard.</li>
    <li><strong>Audit trail completo:</strong> ogni decisione AI e umana è logga con modello, confidenza, latenza e motivazione testuale, permettendo revisioni a posteriori.</li>
    <li><strong>Politica di moderazione versionata:</strong> il system prompt inviato a Claude è gestito tramite UI con versioning; le modifiche sono tracciate con data e autore.</li>
  </ul>

  <h3>R3 — Violazione dati (Data Breach)</h3>

  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    Ai sensi degli artt. 33–34 GDPR, una violazione dei dati personali deve essere notificata
    all'autorità di controllo entro 72 ore dalla scoperta (art. 33) e, se il rischio per gli
    interessati è elevato, anche direttamente agli stessi (art. 34).
  </p>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:.4rem">
    Scenari di breach, probabilità e impatto
  </p>
  <table style="margin-bottom:1.2rem">
    <thead>
      <tr>
        <th style="width:22%">Scenario</th>
        <th style="width:10%">Probabilità</th>
        <th style="width:28%">Impatto</th>
        <th style="width:12%">Notifica art. 33</th>
        <th style="width:12%">Notifica art. 34</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Compromissione credenziali amministratore</strong> (phishing, password debole, SSO compromesso)</td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Accesso a tutta la dashboard, log di moderazione, dati social utenti. Se l'attaccante esporta il DB: esposizione massiva di commenti + storico violazioni + token di appello attivi.</td>
        <td>✅ Obbligatoria</td>
        <td>⚠️ Valutare</td>
      </tr>
      <tr>
        <td><strong>SQL injection / accesso diretto al DB</strong> tramite vulnerabilità applicativa</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Dump completo del database. Dati esposti: commenti, pseudonimi, contatori violazioni, log AI, token di appello, account amministratori.</td>
        <td>✅ Obbligatoria</td>
        <td>✅ Probabile</td>
      </tr>
      <tr>
        <td><strong>Compromissione del server / hosting</strong> (accesso SSH, pannello cPanel)</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Accesso a tutti i file incluso <code>.env</code> (segreti, chiavi API). Possibile esfiltrazione completa del DB e delle chiavi di cifratura.</td>
        <td>✅ Obbligatoria</td>
        <td>✅ Probabile</td>
      </tr>
      <tr>
        <td><strong>Esposizione accidentale del dashboard</strong> (misconfiguration firewall/IP allowlist)</td>
        <td><span class="risk risk-medium">Medio</span></td>
        <td>Dashboard accessibile da internet senza restrizioni IP. In assenza di exploit attivo: solo rischio di brute-force. Con credenziali deboli: accesso ai dati.</td>
        <td>⚠️ Solo se accesso confermato</td>
        <td>❌ Solo se dati esfiltrati</td>
      </tr>
      <tr>
        <td><strong>Insider threat</strong> (moderatore autorizzato che esporta/condivide dati)</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Esportazione non autorizzata di log di moderazione o dati utente. Impatto limitato dalla minimizzazione (nomi reali non presenti nella coda di revisione).</td>
        <td>✅ Obbligatoria</td>
        <td>⚠️ Valutare</td>
      </tr>
      <tr>
        <td><strong>Perdita o furto di backup</strong></td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Se i backup non sono cifrati, un dump del DB contiene tutti i dati personali trattati.</td>
        <td>✅ Se backup in chiaro</td>
        <td>⚠️ Valutare</td>
      </tr>
    </tbody>
  </table>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:.4rem">
    Misure di prevenzione
  </p>
  <ul class="measures">
    <li><strong>Accesso ristretto per IP:</strong> il dashboard è concepito per essere accessibile solo da IP interni o VPN (<code>docs/deployment-security.md</code>). Solo <code>/webhook/meta</code> è pubblico.</li>
    <li><strong>Autenticazione JWT + SSO:</strong> token firmati con <code>APP_SECRET</code> ≥ 32 char; MFA tramite Azure AD/Entra ID per gli amministratori.</li>
    <li><strong>OAUTH_ALLOWED_EMAIL_DOMAINS:</strong> limita il login ai soli account del dominio aziendale, prevenendo accessi con account OAuth personali.</li>
    <li><strong>Segreti distinti e forti:</strong> <code>APP_SECRET</code>, <code>META_WEBHOOK_VERIFY_TOKEN</code>, <code>META_APP_SECRET</code> devono essere valori distinti (verificato dall'installer). Il file <code>.env</code> non deve essere versionato né accessibile via web.</li>
    <li><strong>TLS obbligatorio:</strong> tutte le comunicazioni (dashboard, API, webhook) devono transitare su HTTPS. HTTP deve essere rediretto o bloccato.</li>
    <li><strong>Anonimizzazione programmata:</strong> il cron notturno riduce progressivamente la superficie di esposizione eliminando i PII dopo il periodo configurato.</li>
    <li><strong>Minimizzazione in coda:</strong> il nome reale degli utenti non è mai trasmesso al client in contesto di revisione (blind review), riducendo il valore del dato in caso di intercettazione.</li>
    <li><strong>Backup cifrati:</strong> il titolare si impegna a cifrare i backup del DB. I backup in chiaro non devono essere archiviati su storage accessibile via rete senza autenticazione.</li>
  </ul>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin:.8rem 0 .4rem">
    Rilevamento degli incidenti
  </p>
  <ul class="measures">
    <li><strong>Log di accesso amministratori:</strong> ogni accesso alla dashboard è registrato (IP, user-agent, timestamp). Revisione periodica raccomandata (mensile o automatizzata con alert su login da IP inusuali).</li>
    <li><strong>Audit trail delle decisioni:</strong> ogni azione di moderazione è attribuita a un utente amministratore. Azioni di massa anomale sono rilevabili a posteriori.</li>
    <li><strong>Monitoraggio server:</strong> il titolare deve attivare alert sull'hosting per accessi SSH insoliti, variazioni ai file di configurazione (<code>.env</code>, <code>index.php</code>) e picchi di query DB.</li>
  </ul>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin:.8rem 0 .4rem">
    Procedura di risposta e notifica (artt. 33–34 GDPR)
  </p>
  <table>
    <thead>
      <tr><th style="width:20%">Fase</th><th style="width:20%">Tempistica</th><th>Azioni</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>1. Rilevamento e contenimento</strong></td>
        <td>Immediato (h0)</td>
        <td>Isolare il sistema compromesso (blocco IP, revoca credenziali, spegnimento servizio se necessario). Preservare i log per l'analisi forense. Non cancellare dati che potrebbero servire all'indagine.</td>
      </tr>
      <tr>
        <td><strong>2. Valutazione</strong></td>
        <td>Entro 24h</td>
        <td>Determinare: categorie e volume di dati coinvolti, numero approssimativo di interessati, probabilità di danno per gli interessati (esposizione, uso fraudolento). Coinvolgere il DPO se designato.</td>
      </tr>
      <tr>
        <td><strong>3. Notifica al Garante (art. 33)</strong></td>
        <td>Entro 72h dalla scoperta</td>
        <td>Notifica a <?= htmlspecialchars($supervisory, ENT_QUOTES) ?> tramite il portale telematico dell'autorità. Contenuto obbligatorio: natura della violazione, categorie/numero di interessati, conseguenze probabili, misure adottate. Se non si rispetta il termine delle 72h: indicare i motivi del ritardo.</td>
      </tr>
      <tr>
        <td><strong>4. Notifica agli interessati (art. 34)</strong></td>
        <td>Senza ingiustificato ritardo</td>
        <td>Obbligatoria se il rischio per i diritti e le libertà degli interessati è <em>elevato</em>. Canale: commento di notifica sulla Pagina Facebook + email se disponibile. Contenuto: natura della violazione, contatto DPO, conseguenze probabili, misure adottate o proposte.</td>
      </tr>
      <tr>
        <td><strong>5. Recovery</strong></td>
        <td>Appena possibile</td>
        <td>Ripristino da backup cifrato verificato. Rinnovo di tutti i segreti (<code>APP_SECRET</code>, <code>META_APP_SECRET</code>, chiavi OAuth). Revisione delle misure di sicurezza che hanno fallito. Aggiornamento della presente DPIA.</td>
      </tr>
      <tr>
        <td><strong>6. Registro interno (art. 33.5)</strong></td>
        <td>Permanente</td>
        <td>Documentare la violazione nel registro interno degli incidenti (anche se non notificata al Garante): data scoperta, natura, dati coinvolti, azioni intraprese, decisione su notifica e motivazione.</td>
      </tr>
    </tbody>
  </table>

  <p style="font-size:12px;color:#777;margin-top:-.3rem;margin-bottom:1.5rem">
    <strong>Nota:</strong> la soglia per la notifica al Garante è "rischio per i diritti e le libertà" — non è richiesta certezza del danno, è sufficiente la possibilità. In caso di dubbio, notificare.
  </p>

  <h3>R4 — Trasferimento a Anthropic</h3>
  <ul class="measures">
    <li><strong>Minimizzazione:</strong> vengono inviati ad Anthropic solo il testo del commento e i metadati necessari per il contesto (ID utente, contatore violazioni, nome pagina). Nessun dato identificativo diretto (nome, email, foto) viene trasmesso.</li>
    <li><strong>DPA con Anthropic:</strong> il titolare deve stipulare un Data Processing Agreement con Anthropic e verificare le basi di trasferimento verso USA (SCCs o adeguatezza).</li>
    <li><strong>Nessun training:</strong> i dati inviati ad Anthropic tramite API non vengono usati per addestrare i modelli (policy Anthropic API as of data di redazione).</li>
  </ul>

  <h3>R5 — Bias del modello AI e del revisore umano</h3>
  <ul class="measures">
    <li><strong>Revisione cieca (blind review):</strong> quando un commento è escalato alla revisione umana, il moderatore vede esclusivamente uno pseudonimo interno (es. «Utente #4821»), mai il nome reale Facebook. Il <code>display_name</code> non viene selezionato né trasmesso al client nelle API della coda di revisione (<code>/api/queue</code>, <code>/api/queue/reportable</code>). Elimina il pregiudizio basato su nome, etnia percepita o genere percepito del commentatore.</li>
    <li><strong>Policy configurabile:</strong> il system prompt è modificabile dal titolare per correggere comportamenti sistematicamente errati rilevati nella revisione umana.</li>
    <li><strong>Monitoraggio statistico:</strong> il dashboard mostra la distribuzione delle decisioni per stadio e categoria, permettendo di rilevare deviazioni sistematiche nel comportamento del modello AI.</li>
  </ul>

  <h3>R6 — Dipendenza da servizio terzo</h3>
  <ul class="measures">
    <li><strong>Fail-safe:</strong> se l'API Anthropic non risponde, il commento viene automaticamente escalato alla coda di revisione umana invece di essere nascosto automaticamente.</li>
    <li><strong>Modelli multipli:</strong> la pipeline usa Haiku (cost-efficient) e Sonnet (qualità). L'architettura consente di aggiornare i model ID in configurazione senza modifiche al codice.</li>
  </ul>

  <h3>R7 — Conservazione eccessiva</h3>
  <ul class="measures">
    <li><strong>Monitoraggio cron:</strong> il dashboard mostra la data dell'ultima esecuzione del cron di anonimizzazione e genera un avviso se è più vecchia di 48 ore.</li>
    <li><strong>Reset operativo:</strong> script SQL <code>database/scripts/reset-operational-data.sql</code> disponibile per eliminare completamente i dati operativi mantenendo la configurazione.</li>
  </ul>

  <h3>R8 — Profilazione non dichiarata</h3>
  <ul class="measures">
    <li><strong>Disclosure nella privacy policy:</strong> la privacy policy pubblica del sistema include una sezione dedicata all'uso di AI per la moderazione e al tracciamento del recidivismo. Il titolare deve assicurarsi che sia correttamente pubblicata e aggiornata.</li>
    <li><strong>Diritto di opposizione:</strong> gli utenti possono esercitare il diritto di opposizione (art. 21 GDPR) contattando l'indirizzo privacy del titolare.</li>
  </ul>

  <!-- 7. Rischi residui -->
  <h2>7. Rischi residui e accettabilità</h2>
  <table>
    <thead>
      <tr><th style="width:22%">Rischio</th><th>Livello residuo</th><th>Accettabilità e note</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>R1 — Falso positivo</td>
        <td><span class="risk risk-residual">Basso</span></td>
        <td>Accettabile. L'appello garantisce rimedio entro un termine ragionevole. Nessun dato è cancellato in modo irreversibile senza revisione umana.</td>
      </tr>
      <tr>
        <td>R2 — Ban errato</td>
        <td><span class="risk risk-residual">Basso</span></td>
        <td>Accettabile. I ban sono revocabili dai moderatori in qualsiasi momento. Il sistema richiede più violazioni confermate prima del ban automatico.</td>
      </tr>
      <tr>
        <td>R3 — Violazione dati (data breach)</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Accettabile con applicazione delle misure di hardening (restrizione IP, HTTPS, allowlist domini, backup cifrati, MFA). Il titolare è responsabile della configurazione del server e dell'attivazione della procedura di notifica artt. 33–34 entro 72h in caso di incidente.</td>
      </tr>
      <tr>
        <td>R4 — Trasferimento Anthropic</td>
        <td><span class="risk risk-residual">Basso</span></td>
        <td>Accettabile subordinatamente alla firma del DPA con Anthropic e alla verifica delle basi di trasferimento.</td>
      </tr>
      <tr>
        <td>R5 — Bias AI + pregiudizio revisore</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Accettabile. La blind review elimina il pregiudizio identitario del revisore umano. Il bias residuo del modello AI è contenuto dalla revisione umana e dal monitoraggio statistico.</td>
      </tr>
      <tr>
        <td>R6 — Dipendenza terzo</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Accettabile. Il fail-safe verso la coda umana garantisce continuità del servizio di moderazione anche in caso di indisponibilità AI.</td>
      </tr>
      <tr>
        <td>R7 — Conservazione eccessiva</td>
        <td><span class="risk risk-low">Basso</span></td>
        <td>Accettabile con monitoraggio attivo del cron. Il titolare si impegna a verificare periodicamente l'esecuzione del processo di anonimizzazione.</td>
      </tr>
      <tr>
        <td>R8 — Profilazione</td>
        <td><span class="risk risk-residual">Basso</span></td>
        <td>Accettabile con la corretta pubblicazione della privacy policy e la garanzia del diritto di opposizione.</td>
      </tr>
    </tbody>
  </table>

  <!-- 8. Consultazione DPO -->
  <h2>8. Parere del Responsabile della Protezione dei Dati (DPO)</h2>
  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    Se il titolare ha designato un DPO ai sensi dell'art. 37 GDPR, compilare questa sezione prima dell'approvazione finale.
    Se non è stato designato un DPO, indicarne il motivo (es. "Non obbligatorio — organizzazione al di sotto delle soglie art. 37").
  </p>
  <div class="dpo-notes">
    <p class="placeholder">[ Spazio per il parere del DPO — da compilare manualmente prima dell'approvazione ]</p>
  </div>

  <!-- 9. Consultazione preventiva -->
  <h2>9. Consultazione preventiva dell'autorità di controllo (art. 36)</h2>
  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    Art. 36 GDPR prevede la consultazione preventiva del Garante se i rischi residui rimangono elevati nonostante le misure adottate.
    Sulla base della valutazione nella sez. 7, nessun rischio residuo è classificato come "Alto": la consultazione preventiva non è obbligatoria.
    Se il titolare ritiene opportuna la consultazione in ogni caso, annotarlo qui.
  </p>
  <div class="dpo-notes">
    <p class="placeholder">[ Consultazione preventiva: □ Non necessaria &nbsp;&nbsp; □ Avviata in data __________ &nbsp;&nbsp; □ Parere ricevuto in data __________ ]</p>
  </div>

  <!-- 10. Riesame -->
  <h2>10. Riesame periodico</h2>
  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    La presente DPIA deve essere riesaminata almeno ogni 12 mesi o al verificarsi di cambiamenti significativi nel trattamento
    (nuovi modelli AI, nuove categorie di dati, variazioni della base giuridica, aggiornamenti rilevanti della normativa).
  </p>
  <table>
    <thead>
      <tr><th>Data riesame</th><th>Esito</th><th>Modifiche apportate</th><th>Responsabile</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><?= htmlspecialchars($today, ENT_QUOTES) ?> (redazione iniziale)</td>
        <td>Prima emissione</td>
        <td>—</td>
        <td><?= htmlspecialchars($orgName, ENT_QUOTES) ?></td>
      </tr>
      <tr><td style="color:#ccc;font-style:italic">[ prossimo riesame ]</td><td></td><td></td><td></td></tr>
    </tbody>
  </table>

  <!-- 11. Approvazione -->
  <h2>11. Approvazione e firme</h2>
  <div class="signature">
    <div class="sig-head">Firme di approvazione</div>
    <div class="sig-body">
      <div class="sig-cell">
        <div class="sig-label">Titolare del trattamento</div>
        <div class="sig-line"></div>
        <div class="sig-sub"><?= htmlspecialchars($orgName, ENT_QUOTES) ?></div>
        <div style="font-size:10px;color:#ccc;margin-top:.3rem">Data: _______________</div>
      </div>
      <div class="sig-cell">
        <div class="sig-label">DPO (se designato)</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Nome: _______________</div>
        <div style="font-size:10px;color:#ccc;margin-top:.3rem">Data: _______________</div>
      </div>
      <div class="sig-cell">
        <div class="sig-label">Responsabile IT / Referente sistema</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Nome: _______________</div>
        <div style="font-size:10px;color:#ccc;margin-top:.3rem">Data: _______________</div>
      </div>
    </div>
  </div>

  <!-- Footer -->
  <div class="doc-footer">
    Documento generato automaticamente da Social Moderation Hub v<?= htmlspecialchars($appVersion, ENT_QUOTES) ?>
    — <?= htmlspecialchars($appUrl, ENT_QUOTES) ?>
    &nbsp;·&nbsp; Generato il <?= htmlspecialchars($today, ENT_QUOTES) ?>
  </div>

</div>
</body>
</html>
