<?php
// public/dpia.php
// Valutazione d'Impatto sulla Protezione dei Dati (DPIA) — art. 35 Reg. UE 2016/679 (GDPR)
// Generato automaticamente dal sistema. Non modificare manualmente.
// Chiamato da ModerationController::exportDpia() con extract($vars).

// $retentionDays = 0 significa che l'anonimizzazione automatica non è
// configurata (vedi RetentionService::purge()), non "0 giorni".
$retentionEnabled = ((int) $retentionDays) > 0;
// $violationRetentionDays è già stato risolto dal controller (eredita
// $retentionDays se non impostato esplicitamente); 0 = disattivato anche
// come fallback, cioè nessuna delle due finestre è configurata.
$violationRetentionEnabled = ((int) $violationRetentionDays) > 0;
$violationRetentionDiffers = $violationRetentionEnabled && ((int) $violationRetentionDays !== (int) $retentionDays);
?><!DOCTYPE html>
<html lang="it">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>DPIA – <?= htmlspecialchars($orgName, ENT_QUOTES) ?></title>
  <link rel="stylesheet" href="<?= htmlspecialchars($appUrl, ENT_QUOTES) ?>/assets/css/gdpr-dpia.css?v=<?= (int) (@filemtime(__DIR__ . '/assets/css/gdpr-dpia.css') ?: 0) ?>">
</head>
<body>
<div class="page">

<!-- ═══════════════════════════════════════════════════════════════
     SEZIONE ITALIANA
     ═══════════════════════════════════════════════════════════════ -->
<div class="lang-section" lang="it">

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
        <div class="val"><?php if ($retentionEnabled): ?>Dati operativi conservati per il periodo configurato (finestra GDPR impostata nel sistema). Dopo la scadenza: anonimizzazione automatica (campi PII sostituiti con hash/NULL, colonne statistiche conservate). Attualmente: <?= (int)$retentionDays ?> giorni.<?php else: ?>Anonimizzazione automatica non configurata (parametro di conservazione impostato a 0 = disattivato): i dati operativi restano identificabili senza scadenza automatica, salvo cancellazione manuale o esercizio del diritto all'oblio.<?php endif; ?> <?php if ($violationRetentionDiffers): ?>I dati identificativi degli utenti con almeno una violazione/ban registrati seguono invece una finestra dedicata più lunga: <?= (int)$violationRetentionDays ?> giorni dall'ultima violazione (vedi sez. 6).<?php elseif (!$violationRetentionEnabled): ?>Anche la finestra dedicata ai dati di ban/violazione non è configurata.<?php endif; ?></div>
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
        <td><?php if ($retentionEnabled): ?>Anonimizzazione automatica dopo <?= (int)$retentionDays ?> giorni tramite cron notturno. Il sistema avvisa se il cron non viene eseguito da più di 48 ore.<?php else: ?>Parametro di conservazione non configurato (0 = disattivato): l'anonimizzazione automatica non è attiva. Il Titolare deve impostare una finestra di conservazione per rispettare il principio di limitazione della conservazione (art. 5.1.e GDPR).<?php endif; ?> Per gli utenti con violazioni/ban registrati si applica una finestra separata (sez. 6): <?php if ($violationRetentionDiffers): ?><?= (int)$violationRetentionDays ?> giorni dall'ultima violazione.<?php elseif ($violationRetentionEnabled): ?>coincide con quella generale sopra, non essendo stata impostata separatamente.<?php else: ?>non configurata.<?php endif; ?></td>
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
    <li><strong>DPA con Anthropic:</strong> il DPA con Clausole Contrattuali Standard è incorporato automaticamente nei Commercial Terms of Service di Anthropic, accettati al momento della sottoscrizione dell'API key — nessun documento separato da firmare.</li>
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
    <li><strong>Doppia finestra di conservazione:</strong> il sistema distingue tra dati identificativi degli utenti senza violazioni (<code>data_retention_days</code>) e dati identificativi degli utenti con almeno una violazione/ban (<code>violation_retention_days</code>, finestra dedicata e opzionalmente più lunga — se non impostata, eredita la finestra generale). Il conteggio delle violazioni/ban resta comunque statistico e non identificativo dopo l'anonimizzazione, indipendentemente dalla finestra applicata.</li>
    <li><strong>Monitoraggio cron:</strong> il dashboard mostra la data dell'ultima esecuzione del cron di anonimizzazione e genera un avviso se è più vecchia di 48 ore.</li>
    <li><strong>Reset operativo:</strong> script SQL <code>database/scripts/reset-operational-data.sql</code> disponibile per eliminare completamente i dati operativi mantenendo la configurazione.</li>
    <li><strong>Strumento DSAR (ricerca/export/anonimizzazione manuale):</strong> pannello admin-only (Settings → Privacy) che consente di cercare, esportare (art. 15/20 GDPR) e anonimizzare in-place (art. 17 GDPR) i dati di un singolo utente social su richiesta, indipendentemente dalle finestre automatiche sopra. Conferma in due passaggi con motivazione obbligatoria, ogni operazione registrata in <code>gdpr_audit_log</code>.</li>
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
        <td>Accettabile; il DPA con Clausole Contrattuali Standard è incorporato automaticamente nei Commercial Terms of Service di Anthropic, accettati al momento della sottoscrizione dell'API key.</td>
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

</div><!-- /lang-section IT -->

<hr class="divider">

<!-- ═══════════════════════════════════════════════════════════════
     ENGLISH SECTION
     ═══════════════════════════════════════════════════════════════ -->
<div class="lang-section" lang="en">

  <!-- Document header -->
  <div class="doc-header">
    <div class="doc-title">Data Protection Impact Assessment (DPIA)</div>
    <div class="doc-sub">Data Protection Impact Assessment — Art. 35 Reg. (EU) 2016/679 (GDPR)</div>
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
    <p><strong>Supervisory authority:</strong> <?= htmlspecialchars($supervisory, ENT_QUOTES) ?></p>
    <p><strong>System URL:</strong> <?= htmlspecialchars($appUrl, ENT_QUOTES) ?></p>
  </div>

  <!-- 2. Description of the processing -->
  <h2>2. Description of the processing and the system</h2>
  <div class="system-card">
    <div class="sc-head">Social Moderation Hub — automated moderation pipeline</div>
    <div class="sc-body">
      <div class="sc-cell">
        <div class="label">Purpose</div>
        <div class="val">Automated moderation of comments published on the connected Facebook Page(s), in order to detect content that violates the Controller's editorial policy (spam, hate speech, scams, disinformation, etc.) and protect users from potentially unlawful content.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Legal basis</div>
        <div class="val">Art. 6(1)(f) GDPR — the Controller's legitimate interest in ensuring the safety and lawfulness of its online presence and protecting the community from harmful content. The overriding interest is verified in the attached balancing test (sec. 4).</div>
      </div>
      <div class="sc-cell">
        <div class="label">Categories of data processed</div>
        <div class="val">Facebook user ID (pseudonymised), comment text, timestamp, post/page ID; internal violation counters; ban flags; AI decision logs (model, confidence, latency, textual reasoning).</div>
      </div>
      <div class="sc-cell">
        <div class="label">Categories of data subjects</div>
        <div class="val">Facebook users who comment on the connected Page(s)' posts. No special categories of data under Art. 9 GDPR are processed, unless the comment content incidentally contains them.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Processing pipeline</div>
        <div class="val">Meta webhook → Claude Haiku (first stage) → Claude Sonnet (escalation) → human review queue. Data is sent to Anthropic via API for inference only; no fine-tuning or training occurs on data subjects' data.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Transfers outside the EU</div>
        <div class="val">Anthropic PBC (USA) — comment text sent for AI inference. Basis: Standard Contractual Clauses (SCCs) or a specific DPA. Meta Platforms (USA/Ireland) — data already present on the originating platform.</div>
      </div>
      <div class="sc-cell">
        <div class="label">Retention</div>
        <div class="val"><?php if ($retentionEnabled): ?>Operational data is retained for the configured period (GDPR window set in the system). After expiry: automatic anonymisation (PII fields replaced with hashes/NULL, statistical columns retained). Currently: <?= (int)$retentionDays ?> days.<?php else: ?>Automatic anonymisation is not configured (retention parameter set to 0 = disabled): operational data remains identifiable with no automatic expiry, subject only to manual deletion or the exercise of the right to erasure.<?php endif; ?> <?php if ($violationRetentionDiffers): ?>Identifying data for users with at least one recorded violation/ban instead follows a dedicated, longer window: <?= (int)$violationRetentionDays ?> days from the last violation (see sec. 6).<?php elseif (!$violationRetentionEnabled): ?>The dedicated window for violation/ban data is not configured either.<?php endif; ?></div>
      </div>
      <div class="sc-cell">
        <div class="label">Current statistics</div>
        <div class="val">
          Comments: <?= number_format((int)$totComments) ?> &nbsp;·&nbsp;
          Users: <?= number_format((int)$totUsers) ?> &nbsp;·&nbsp;
          Active bans: <?= number_format((int)$totBans) ?> &nbsp;·&nbsp;
          Connected pages: <?= number_format((int)$totPages) ?>
        </div>
      </div>
    </div>
  </div>

  <!-- 3. Necessity and proportionality -->
  <h2>3. Necessity and proportionality</h2>
  <table>
    <thead>
      <tr><th style="width:30%">Criterion</th><th>Assessment</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Purpose limitation</strong></td>
        <td>Data is processed exclusively for editorial moderation purposes on the Controller's Facebook Page. It is not used for commercial profiling, advertising, or sale to third parties.</td>
      </tr>
      <tr>
        <td><strong>Data minimisation</strong></td>
        <td>Only the data strictly necessary is retained: pseudonymised ID, comment text, timestamp and moderation metrics. No address, e-mail or other identifying data of the Facebook user is collected.</td>
      </tr>
      <tr>
        <td><strong>Storage limitation</strong></td>
        <td><?php if ($retentionEnabled): ?>Automatic anonymisation after <?= (int)$retentionDays ?> days via a nightly cron job. The system warns if the cron has not run for more than 48 hours.<?php else: ?>Retention parameter not configured (0 = disabled): automatic anonymisation is not active. The Controller must set a retention window to comply with the storage limitation principle (Art. 5.1.e GDPR).<?php endif; ?> A separate window applies to users with a recorded violation/ban (sec. 6): <?php if ($violationRetentionDiffers): ?><?= (int)$violationRetentionDays ?> days from the last violation.<?php elseif ($violationRetentionEnabled): ?>same as the general window above, since none was set separately.<?php else: ?>not configured.<?php endif; ?></td>
      </tr>
      <tr>
        <td><strong>Accuracy</strong></td>
        <td>AI decisions carry a confidence score. Below the threshold, the comment is escalated to human review. Human decisions override AI decisions and are logged separately.</td>
      </tr>
      <tr>
        <td><strong>Transparency</strong></td>
        <td>The public Privacy Policy (<code><?= htmlspecialchars($appUrl, ENT_QUOTES) ?>/privacy</code>) informs data subjects of the use of AI for moderation, the legitimate interest applied, and their rights (access, erasure, objection).</td>
      </tr>
      <tr>
        <td><strong>Data subject rights</strong></td>
        <td>Built-in appeal workflow: hidden comments include a cryptographically signed appeal link (URL valid for 30 days) that the user can use to contest the decision. Moderators review and respond.</td>
      </tr>
    </tbody>
  </table>

  <!-- 4. Risk factors -->
  <h2>4. Factors triggering the DPIA obligation (Art. 35(3) + WP248 guidelines)</h2>
  <table>
    <thead>
      <tr><th style="width:35%">WP248 criterion</th><th style="width:12%">Present</th><th>Detail</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Evaluation / scoring</td>
        <td style="text-align:center">✅</td>
        <td>The system assigns a risk score to each comment and tracks a per-user violation history (recidivism).</td>
      </tr>
      <tr>
        <td>Automated decisions with significant effects</td>
        <td style="text-align:center">✅</td>
        <td>An AI decision can hide or remove a comment fully automatically (without human intervention) when confidence exceeds the configured threshold.</td>
      </tr>
      <tr>
        <td>Systematic monitoring</td>
        <td style="text-align:center">✅</td>
        <td>Every comment published on the Page is captured and analysed in real time via the Meta webhook.</td>
      </tr>
      <tr>
        <td>Large-scale data</td>
        <td style="text-align:center">⚠️</td>
        <td>Scale depends on the Page's audience. With a large audience the volume can be significant. The Controller must assess whether its installation exceeds the "large scale" threshold.</td>
      </tr>
      <tr>
        <td>Special category data (Art. 9)</td>
        <td style="text-align:center">⚠️</td>
        <td>Not intentionally processed, but user comments may contain political or religious opinions or health data. The system does not extract or store such data in dedicated fields.</td>
      </tr>
      <tr>
        <td>Matching / combining datasets</td>
        <td style="text-align:center">✅</td>
        <td>The per-user violation history (recidivism counter, active bans) is combined with the current comment's content to enrich the context sent to Claude.</td>
      </tr>
      <tr>
        <td>Vulnerability of data subjects</td>
        <td style="text-align:center">⚠️</td>
        <td>The Facebook user base may include minors. The system does not intentionally process minors' data, but cannot exclude them.</td>
      </tr>
      <tr>
        <td>Innovative use or application of new technological solutions</td>
        <td style="text-align:center">✅</td>
        <td>Use of a Large Language Model (Anthropic's Claude) to make editorial decisions in an automated manner.</td>
      </tr>
    </tbody>
  </table>
  <p style="font-size:12px;color:#777;margin-top:-.5rem;margin-bottom:1.5rem">
    <strong>Conclusion:</strong> At least 3 high-risk criteria are present (scoring, automated decisions, systematic monitoring), a threshold that makes the DPIA mandatory under Art. 35(1) and the EDPB's WP248 guidelines.
  </p>

  <!-- 5. Risk identification -->
  <h2>5. Risk identification and assessment</h2>
  <table>
    <thead>
      <tr>
        <th style="width:22%">Risk</th>
        <th style="width:10%">Initial level</th>
        <th style="width:38%">Scenario</th>
        <th style="width:10%">Residual level</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>R1 — False positive: hiding of legitimate content</strong></td>
        <td><span class="risk risk-high">High</span></td>
        <td>The AI model wrongly classifies a legitimate comment as a violation and automatically hides it, limiting the user's freedom of expression.</td>
        <td><span class="risk risk-residual">Low</span></td>
      </tr>
      <tr>
        <td><strong>R2 — Incorrect ban escalation (recidivism)</strong></td>
        <td><span class="risk risk-high">High</span></td>
        <td>A user is temporarily or permanently banned due to false positives accumulated over time, without having actually violated the rules.</td>
        <td><span class="risk risk-residual">Low</span></td>
      </tr>
      <tr>
        <td><strong>R3 — Data breach</strong></td>
        <td><span class="risk risk-high">High</span></td>
        <td>Unauthorised access to the database (compromised credentials, SQL injection, server compromise, insider threat, unencrypted backups). Exposed data: comments, pseudonyms, violation history, AI logs, appeal tokens, administrator accounts. Notification obligation under Arts. 33–34 GDPR.</td>
        <td><span class="risk risk-low">Low</span></td>
      </tr>
      <tr>
        <td><strong>R4 — Data transfer to Anthropic</strong></td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>The comment text (potentially containing personal data) is sent to Anthropic (USA) for AI inference.</td>
        <td><span class="risk risk-residual">Low</span></td>
      </tr>
      <tr>
        <td><strong>R5 — Bias in the AI model and human reviewer</strong></td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>The AI model may show systematic biases. Human moderators may introduce identity-based bias (name, perceived ethnicity, perceived gender) toward the comment's author.</td>
        <td><span class="risk risk-low">Low</span></td>
      </tr>
      <tr>
        <td><strong>R6 — Dependency on a third-party service</strong></td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>Unavailability of the Anthropic API or model changes (deprecation, behaviour change) that alter moderation quality without notice.</td>
        <td><span class="risk risk-residual">Low</span></td>
      </tr>
      <tr>
        <td><strong>R7 — Excessive retention</strong></td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>Failure to run the anonymisation cron job leads to PII being retained beyond the configured period.</td>
        <td><span class="risk risk-low">Low</span></td>
      </tr>
      <tr>
        <td><strong>R8 — Undisclosed profiling</strong></td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>The recidivism counter builds a behavioural profile of the user over time, not explicitly declared as "profiling" in the public privacy policy.</td>
        <td><span class="risk risk-residual">Low</span></td>
      </tr>
    </tbody>
  </table>

  <!-- 6. Mitigation measures -->
  <h2>6. Technical and organisational mitigation measures</h2>

  <h3>R1 + R2 — False positives and unjust bans</h3>
  <ul class="measures">
    <li><strong>Two-stage pipeline + threshold:</strong> Haiku decides only if it exceeds the confidence threshold; below threshold, Sonnet re-evaluates. Below the Sonnet threshold, the comment goes to the human queue without any automated action.</li>
    <li><strong>Signed appeal:</strong> every automatically hidden comment includes a cryptographically signed appeal link (HMAC-SHA256, 30-day expiry) that the user can use to contest the decision; a human moderator reviews it and can restore the comment.</li>
    <li><strong>Progressive ban:</strong> an automatic ban requires multiple confirmed violations (configurable threshold via <code>RECIDIVISM_COMMENT_BAN_LIMIT</code>). Moderators can manually revoke any ban from the dashboard.</li>
    <li><strong>Full audit trail:</strong> every AI and human decision is logged with model, confidence, latency and textual reasoning, enabling after-the-fact review.</li>
    <li><strong>Versioned moderation policy:</strong> the system prompt sent to Claude is managed through a versioned UI; changes are tracked with date and author.</li>
  </ul>

  <h3>R3 — Data Breach</h3>

  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    Under Arts. 33–34 GDPR, a personal data breach must be notified to the supervisory
    authority within 72 hours of discovery (Art. 33) and, if the risk to data subjects
    is high, directly to them as well (Art. 34).
  </p>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:.4rem">
    Breach scenarios, likelihood and impact
  </p>
  <table style="margin-bottom:1.2rem">
    <thead>
      <tr>
        <th style="width:22%">Scenario</th>
        <th style="width:10%">Likelihood</th>
        <th style="width:28%">Impact</th>
        <th style="width:12%">Art. 33 notification</th>
        <th style="width:12%">Art. 34 notification</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Administrator credential compromise</strong> (phishing, weak password, compromised SSO)</td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>Access to the entire dashboard, moderation log, social user data. If the attacker exports the DB: massive exposure of comments + violation history + active appeal tokens.</td>
        <td>✅ Mandatory</td>
        <td>⚠️ To be assessed</td>
      </tr>
      <tr>
        <td><strong>SQL injection / direct DB access</strong> via an application vulnerability</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Full database dump. Exposed data: comments, pseudonyms, violation counters, AI logs, appeal tokens, administrator accounts.</td>
        <td>✅ Mandatory</td>
        <td>✅ Likely</td>
      </tr>
      <tr>
        <td><strong>Server / hosting compromise</strong> (SSH access, cPanel panel)</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Access to all files including <code>.env</code> (secrets, API keys). Possible full exfiltration of the DB and encryption keys.</td>
        <td>✅ Mandatory</td>
        <td>✅ Likely</td>
      </tr>
      <tr>
        <td><strong>Accidental dashboard exposure</strong> (firewall/IP allowlist misconfiguration)</td>
        <td><span class="risk risk-medium">Medium</span></td>
        <td>Dashboard reachable from the internet without restrictions. Absent an active exploit: only brute-force risk. With weak credentials: data access.</td>
        <td>⚠️ Only if access confirmed</td>
        <td>❌ Only if data exfiltrated</td>
      </tr>
      <tr>
        <td><strong>Insider threat</strong> (authorised moderator exporting/sharing data)</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Unauthorised export of moderation logs or user data. Impact limited by minimisation (real names are not present in the review queue).</td>
        <td>✅ Mandatory</td>
        <td>⚠️ To be assessed</td>
      </tr>
      <tr>
        <td><strong>Loss or theft of backups</strong></td>
        <td><span class="risk risk-low">Low</span></td>
        <td>If backups are not encrypted, a DB dump contains all the personal data processed.</td>
        <td>✅ If backups unencrypted</td>
        <td>⚠️ To be assessed</td>
      </tr>
    </tbody>
  </table>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin-bottom:.4rem">
    Prevention measures
  </p>
  <ul class="measures">
    <li><strong>IP-restricted access:</strong> the dashboard is designed to be reachable only from internal IPs or VPN (<code>docs/deployment-security.md</code>). Only <code>/webhook/meta</code> is public.</li>
    <li><strong>JWT authentication + SSO:</strong> tokens signed with <code>APP_SECRET</code> ≥ 32 chars; MFA via Azure AD/Entra ID for administrators.</li>
    <li><strong>OAUTH_ALLOWED_EMAIL_DOMAINS:</strong> restricts login to the corporate domain's accounts only, preventing access with personal OAuth accounts.</li>
    <li><strong>Distinct, strong secrets:</strong> <code>APP_SECRET</code>, <code>META_WEBHOOK_VERIFY_TOKEN</code>, <code>META_APP_SECRET</code> must be distinct values (verified by the installer). The <code>.env</code> file must not be version-controlled or web-accessible.</li>
    <li><strong>TLS mandatory:</strong> all communications (dashboard, API, webhook) must go over HTTPS. HTTP must be redirected or blocked.</li>
    <li><strong>Scheduled anonymisation:</strong> the nightly cron progressively reduces the exposure surface by removing PII after the configured period.</li>
    <li><strong>Minimisation in the queue:</strong> users' real names are never transmitted to the client in the review context (blind review), reducing the value of the data in the event of interception.</li>
    <li><strong>Encrypted backups:</strong> the Controller commits to encrypting DB backups. Unencrypted backups must not be stored on network-accessible storage without authentication.</li>
  </ul>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin:.8rem 0 .4rem">
    Incident detection
  </p>
  <ul class="measures">
    <li><strong>Administrator access logs:</strong> every dashboard access is logged (IP, user-agent, timestamp). Periodic review recommended (monthly or automated with alerts on logins from unusual IPs).</li>
    <li><strong>Decision audit trail:</strong> every moderation action is attributed to an administrator user. Anomalous bulk actions can be detected after the fact.</li>
    <li><strong>Server monitoring:</strong> the Controller must enable hosting alerts for unusual SSH access, changes to configuration files (<code>.env</code>, <code>index.php</code>) and DB query spikes.</li>
  </ul>

  <p style="font-size:12px;font-weight:700;text-transform:uppercase;letter-spacing:.5px;color:#555;margin:.8rem 0 .4rem">
    Response and notification procedure (Arts. 33–34 GDPR)
  </p>
  <table>
    <thead>
      <tr><th style="width:20%">Phase</th><th style="width:20%">Timing</th><th>Actions</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>1. Detection and containment</strong></td>
        <td>Immediate (h0)</td>
        <td>Isolate the compromised system (IP block, credential revocation, service shutdown if necessary). Preserve logs for forensic analysis. Do not delete data that might be needed for the investigation.</td>
      </tr>
      <tr>
        <td><strong>2. Assessment</strong></td>
        <td>Within 24h</td>
        <td>Determine: categories and volume of data involved, approximate number of data subjects, probability of harm to data subjects (exposure, fraudulent use). Involve the DPO if appointed.</td>
      </tr>
      <tr>
        <td><strong>3. Notification to the supervisory authority (Art. 33)</strong></td>
        <td>Within 72h of discovery</td>
        <td>Notification to <?= htmlspecialchars($supervisory, ENT_QUOTES) ?> via the authority's online portal. Mandatory content: nature of the breach, categories/number of data subjects, likely consequences, measures taken. If the 72h deadline is not met: state the reasons for the delay.</td>
      </tr>
      <tr>
        <td><strong>4. Notification to data subjects (Art. 34)</strong></td>
        <td>Without undue delay</td>
        <td>Mandatory if the risk to data subjects' rights and freedoms is <em>high</em>. Channel: notification comment on the Facebook Page + e-mail if available. Content: nature of the breach, DPO contact, likely consequences, measures taken or proposed.</td>
      </tr>
      <tr>
        <td><strong>5. Recovery</strong></td>
        <td>As soon as possible</td>
        <td>Restore from a verified encrypted backup. Rotate all secrets (<code>APP_SECRET</code>, <code>META_APP_SECRET</code>, OAuth keys). Review the security measures that failed. Update this DPIA.</td>
      </tr>
      <tr>
        <td><strong>6. Internal register (Art. 33.5)</strong></td>
        <td>Permanent</td>
        <td>Document the breach in the internal incident register (even if not notified to the authority): discovery date, nature, data involved, actions taken, notification decision and reasoning.</td>
      </tr>
    </tbody>
  </table>

  <p style="font-size:12px;color:#777;margin-top:-.3rem;margin-bottom:1.5rem">
    <strong>Note:</strong> the threshold for notifying the supervisory authority is "risk to rights and freedoms" — certainty of harm is not required, possibility is sufficient. When in doubt, notify.
  </p>

  <h3>R4 — Transfer to Anthropic</h3>
  <ul class="measures">
    <li><strong>Minimisation:</strong> only the comment text and the metadata necessary for context (user ID, violation counter, page name) are sent to Anthropic. No direct identifying data (name, e-mail, photo) is transmitted.</li>
    <li><strong>DPA with Anthropic:</strong> the DPA with Standard Contractual Clauses is automatically incorporated into Anthropic's Commercial Terms of Service, accepted when the API key was issued — no separate document to sign.</li>
    <li><strong>No training:</strong> data sent to Anthropic via the API is not used to train the models (Anthropic API policy as of the drafting date).</li>
  </ul>

  <h3>R5 — Bias in the AI model and human reviewer</h3>
  <ul class="measures">
    <li><strong>Blind review:</strong> when a comment is escalated to human review, the moderator sees only an internal pseudonym (e.g. "User #4821"), never the real Facebook name. The <code>display_name</code> is neither selected nor transmitted to the client in the review queue APIs (<code>/api/queue</code>, <code>/api/queue/reportable</code>). This eliminates bias based on the commenter's name, perceived ethnicity or perceived gender.</li>
    <li><strong>Configurable policy:</strong> the system prompt can be modified by the Controller to correct systematically incorrect behaviour detected during human review.</li>
    <li><strong>Statistical monitoring:</strong> the dashboard shows the distribution of decisions by stage and category, allowing systematic deviations in the AI model's behaviour to be detected.</li>
  </ul>

  <h3>R6 — Dependency on a third-party service</h3>
  <ul class="measures">
    <li><strong>Fail-safe:</strong> if the Anthropic API does not respond, the comment is automatically escalated to the human review queue instead of being automatically hidden.</li>
    <li><strong>Multiple models:</strong> the pipeline uses Haiku (cost-efficient) and Sonnet (quality). The architecture allows model IDs to be updated in configuration without code changes.</li>
  </ul>

  <h3>R7 — Excessive retention</h3>
  <ul class="measures">
    <li><strong>Dual retention window:</strong> the system distinguishes identifying data for users with no recorded violation (<code>data_retention_days</code>) from identifying data for users with at least one recorded violation/ban (<code>violation_retention_days</code>, a dedicated and optionally longer window — falling back to the general window if unset). The violation/ban count itself remains statistical and non-identifying after anonymisation, regardless of which window applied.</li>
    <li><strong>Cron monitoring:</strong> the dashboard shows the date of the last anonymisation cron run and generates a warning if it is older than 48 hours.</li>
    <li><strong>Operational reset:</strong> the SQL script <code>database/scripts/reset-operational-data.sql</code> is available to fully delete operational data while keeping the configuration.</li>
    <li><strong>DSAR tool (manual search/export/anonymisation):</strong> an admin-only panel (Settings → Privacy) to search, export (Art. 15/20 GDPR) and anonymise in place (Art. 17 GDPR) a single social user's data on request, independent of the automatic retention windows above. Two-step confirmation with a mandatory reason, every operation logged to <code>gdpr_audit_log</code>.</li>
  </ul>

  <h3>R8 — Undisclosed profiling</h3>
  <ul class="measures">
    <li><strong>Disclosure in the privacy policy:</strong> the system's public privacy policy includes a dedicated section on the use of AI for moderation and recidivism tracking. The Controller must ensure it is correctly published and kept up to date.</li>
    <li><strong>Right to object:</strong> users may exercise their right to object (Art. 21 GDPR) by contacting the Controller's privacy address.</li>
  </ul>

  <!-- 7. Residual risks -->
  <h2>7. Residual risks and acceptability</h2>
  <table>
    <thead>
      <tr><th style="width:22%">Risk</th><th>Residual level</th><th>Acceptability and notes</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>R1 — False positive</td>
        <td><span class="risk risk-residual">Low</span></td>
        <td>Acceptable. The appeal guarantees a remedy within a reasonable time. No data is irreversibly deleted without human review.</td>
      </tr>
      <tr>
        <td>R2 — Incorrect ban</td>
        <td><span class="risk risk-residual">Low</span></td>
        <td>Acceptable. Bans can be revoked by moderators at any time. The system requires multiple confirmed violations before an automatic ban.</td>
      </tr>
      <tr>
        <td>R3 — Data breach</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Acceptable with the hardening measures applied (IP restriction, HTTPS, domain allowlist, encrypted backups, MFA). The Controller is responsible for server configuration and activating the Arts. 33–34 notification procedure within 72h in the event of an incident.</td>
      </tr>
      <tr>
        <td>R4 — Anthropic transfer</td>
        <td><span class="risk risk-residual">Low</span></td>
        <td>Acceptable; the DPA with Standard Contractual Clauses is automatically incorporated into Anthropic's Commercial Terms of Service, accepted when the API key was issued.</td>
      </tr>
      <tr>
        <td>R5 — AI bias + reviewer bias</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Acceptable. Blind review eliminates the human reviewer's identity-based bias. The residual AI model bias is contained by human review and statistical monitoring.</td>
      </tr>
      <tr>
        <td>R6 — Third-party dependency</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Acceptable. The fail-safe to the human queue guarantees continuity of the moderation service even if AI is unavailable.</td>
      </tr>
      <tr>
        <td>R7 — Excessive retention</td>
        <td><span class="risk risk-low">Low</span></td>
        <td>Acceptable with active cron monitoring. The Controller commits to periodically verifying execution of the anonymisation process.</td>
      </tr>
      <tr>
        <td>R8 — Profiling</td>
        <td><span class="risk risk-residual">Low</span></td>
        <td>Acceptable with correct publication of the privacy policy and the guarantee of the right to object.</td>
      </tr>
    </tbody>
  </table>

  <!-- 8. DPO consultation -->
  <h2>8. Opinion of the Data Protection Officer (DPO)</h2>
  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    If the Controller has appointed a DPO under Art. 37 GDPR, complete this section before final approval.
    If no DPO has been appointed, state the reason (e.g. "Not mandatory — organisation below the Art. 37 thresholds").
  </p>
  <div class="dpo-notes">
    <p class="placeholder">[ Space for the DPO's opinion — to be filled in manually before approval ]</p>
  </div>

  <!-- 9. Prior consultation -->
  <h2>9. Prior consultation with the supervisory authority (Art. 36)</h2>
  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    Art. 36 GDPR requires prior consultation with the supervisory authority if the residual risks remain high despite the measures adopted.
    Based on the assessment in sec. 7, no residual risk is classified as "High": prior consultation is not mandatory.
    If the Controller considers consultation appropriate regardless, note it here.
  </p>
  <div class="dpo-notes">
    <p class="placeholder">[ Prior consultation: □ Not necessary &nbsp;&nbsp; □ Started on __________ &nbsp;&nbsp; □ Opinion received on __________ ]</p>
  </div>

  <!-- 10. Review -->
  <h2>10. Periodic review</h2>
  <p style="font-size:12.5px;color:#555;margin-bottom:.8rem">
    This DPIA must be reviewed at least every 12 months or whenever significant changes occur in the processing
    (new AI models, new data categories, changes to the legal basis, relevant regulatory updates).
  </p>
  <table>
    <thead>
      <tr><th>Review date</th><th>Outcome</th><th>Changes made</th><th>Responsible</th></tr>
    </thead>
    <tbody>
      <tr>
        <td><?= htmlspecialchars($today, ENT_QUOTES) ?> (initial drafting)</td>
        <td>First issue</td>
        <td>—</td>
        <td><?= htmlspecialchars($orgName, ENT_QUOTES) ?></td>
      </tr>
      <tr><td style="color:#ccc;font-style:italic">[ next review ]</td><td></td><td></td><td></td></tr>
    </tbody>
  </table>

  <!-- 11. Approval -->
  <h2>11. Approval and signatures</h2>
  <div class="signature">
    <div class="sig-head">Approval signatures</div>
    <div class="sig-body">
      <div class="sig-cell">
        <div class="sig-label">Data Controller</div>
        <div class="sig-line"></div>
        <div class="sig-sub"><?= htmlspecialchars($orgName, ENT_QUOTES) ?></div>
        <div style="font-size:10px;color:#ccc;margin-top:.3rem">Date: _______________</div>
      </div>
      <div class="sig-cell">
        <div class="sig-label">DPO (if appointed)</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Name: _______________</div>
        <div style="font-size:10px;color:#ccc;margin-top:.3rem">Date: _______________</div>
      </div>
      <div class="sig-cell">
        <div class="sig-label">IT manager / system contact</div>
        <div class="sig-line"></div>
        <div class="sig-sub">Name: _______________</div>
        <div style="font-size:10px;color:#ccc;margin-top:.3rem">Date: _______________</div>
      </div>
    </div>
  </div>

</div><!-- /lang-section EN -->

  <!-- Footer -->
  <div class="doc-footer">
    <span>
      Documento generato automaticamente da Social Moderation Hub v<?= htmlspecialchars($appVersion, ENT_QUOTES) ?> — <?= htmlspecialchars($appUrl, ENT_QUOTES) ?> &nbsp;·&nbsp; Generato il <?= htmlspecialchars($today, ENT_QUOTES) ?><br>
      Document automatically generated by Social Moderation Hub v<?= htmlspecialchars($appVersion, ENT_QUOTES) ?> — <?= htmlspecialchars($appUrl, ENT_QUOTES) ?> &nbsp;·&nbsp; Generated on <?= htmlspecialchars($today, ENT_QUOTES) ?>
    </span>
    <span class="no-print" style="display:block;margin-top:.6rem">
      <a href="javascript:window.print()" style="color:#555;text-decoration:none">🖨 Stampa / Salva PDF</a>
    </span>
  </div>

</div>
</body>
</html>
