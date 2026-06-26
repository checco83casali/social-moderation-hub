<?php
// public/registro.php
// Registro delle Attività di Trattamento — art. 30 Reg. UE 2016/679 (GDPR)
// Generato automaticamente dal sistema. Non modificare manualmente.
// Chiamato da ModerationController::registroTrattamenti() con extract($vars).
?><!DOCTYPE html>
<html lang="it">
<head>
<meta name="robots" content="noindex, nofollow, noarchive">
  <meta charset="utf-8">
  <meta name="viewport" content="width=device-width, initial-scale=1">
  <title>Registro dei Trattamenti – <?= htmlspecialchars($orgName, ENT_QUOTES) ?></title>
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

    /* Section */
    h2 { font-size: .85rem; font-weight: 700; text-transform: uppercase;
          letter-spacing: .8px; color: #1a1a2e; margin: 2.5rem 0 1rem;
          padding-bottom: .4rem; border-bottom: 1px solid #ddd; }

    /* Titolare box */
    .titolare { background: #f5f7ff; border: 1px solid #c8d0f0; border-radius: 6px;
                 padding: 1rem 1.25rem; margin-bottom: 1.5rem; font-size: 13px; }
    .titolare p { margin-bottom: .3rem; }
    .titolare strong { display: inline-block; min-width: 160px; color: #333; }

    /* Trattamento card */
    .trattamento { border: 1px solid #dde; border-radius: 6px; margin-bottom: 1.5rem;
                    overflow: hidden; }
    .t-head { background: #1a1a2e; color: #fff; padding: .7rem 1.1rem;
               display: flex; align-items: center; justify-content: space-between; gap: 8px; flex-wrap: wrap; }
    .t-num  { font-size: 10px; font-weight: 700; letter-spacing: .5px; opacity: .6;
               text-transform: uppercase; }
    .t-name { font-size: 13.5px; font-weight: 600; }
    .t-body { display: grid; grid-template-columns: 1fr 1fr; gap: 0; }
    .t-cell { padding: .75rem 1.1rem; border-bottom: 1px solid #eef;
               border-right: 1px solid #eef; font-size: 12.5px; }
    .t-cell:nth-child(even) { border-right: none; }
    .t-cell:nth-last-child(-n+2) { border-bottom: none; }
    .t-label { font-size: 10.5px; font-weight: 700; text-transform: uppercase;
                letter-spacing: .5px; color: #888; margin-bottom: .3rem; }
    .t-val   { color: #222; line-height: 1.55; }
    .t-val ul { padding-left: 1rem; margin-top: .2rem; }
    .t-val li { margin-bottom: .15rem; }
    .t-full  { grid-column: 1 / -1; border-right: none; }
    @media(max-width:600px){ .t-body { grid-template-columns: 1fr; }
      .t-cell { border-right: none; } .t-full { grid-column: 1; } }

    /* Sub-processor table */
    table { width: 100%; border-collapse: collapse; font-size: 12px; margin-bottom: 1.5rem; }
    th { background: #f0f2ff; text-align: left; padding: .5rem .8rem;
          border: 1px solid #dde; font-weight: 600; color: #333; }
    td { padding: .45rem .8rem; border: 1px solid #dde; vertical-align: top; color: #444; }
    tr:nth-child(even) td { background: #fafbff; }

    /* Misure di sicurezza */
    .sicurezza { display: grid; grid-template-columns: repeat(3, 1fr); gap: 12px; margin-bottom: 1.5rem; }
    .sic-item  { background: #f5f7ff; border: 1px solid #dde; border-radius: 6px;
                  padding: .75rem 1rem; font-size: 12px; }
    .sic-item strong { display: block; font-size: 11px; text-transform: uppercase;
                         letter-spacing: .4px; color: #666; margin-bottom: .3rem; }
    @media(max-width:600px){ .sicurezza { grid-template-columns: 1fr; } }

    /* Stats box */
    .stats { display: flex; gap: 12px; flex-wrap: wrap; margin-bottom: 1.5rem; }
    .stat  { background: #f5f7ff; border: 1px solid #dde; border-radius: 6px;
               padding: .6rem 1rem; text-align: center; min-width: 100px; }
    .stat-n { font-size: 1.4rem; font-weight: 700; color: #1a1a2e; }
    .stat-l { font-size: 10.5px; color: #888; text-transform: uppercase; letter-spacing: .4px; }

    /* Footer */
    .doc-footer { margin-top: 3rem; padding-top: 1rem; border-top: 1px solid #ddd;
                   font-size: 11px; color: #999; display: flex;
                   justify-content: space-between; flex-wrap: wrap; gap: 6px; }

    /* Print */
    @media print {
      body { padding: 0; }
      .no-print { display: none; }
      .trattamento { break-inside: avoid; }
    }
  </style>
</head>
<body>
<div class="page">

  <!-- ── Intestazione documento ── -->
  <div class="doc-header">
    <div class="doc-title">Registro delle Attività di Trattamento</div>
    <div class="doc-sub">ai sensi dell'art. 30 del Regolamento (UE) 2016/679 (GDPR)</div>
    <div class="doc-meta">
      <span><strong>Titolare:</strong> <?= htmlspecialchars($orgName, ENT_QUOTES) ?></span>
      <span><strong>Data generazione:</strong> <?= $today ?></span>
      <span><strong>Sistema:</strong> Social Moderation Hub</span>
      <span><strong>Policy attiva:</strong> <?= htmlspecialchars($policyName, ENT_QUOTES) ?></span>
    </div>
  </div>

  <!-- ── 1. Titolare del trattamento ── -->
  <h2>1. Titolare del trattamento</h2>
  <div class="titolare">
    <p><strong>Denominazione:</strong> <?= htmlspecialchars($orgName, ENT_QUOTES) ?></p>
    <p><strong>Sede legale:</strong> <?= htmlspecialchars($orgAddress, ENT_QUOTES) ?></p>
    <p><strong>Contatto DPO/Privacy:</strong> <?= htmlspecialchars($orgEmail, ENT_QUOTES) ?></p>
    <p><strong>Installazione:</strong> <?= htmlspecialchars($appUrl, ENT_QUOTES) ?></p>
  </div>

  <!-- ── 2. Contesto quantitativo ── -->
  <h2>2. Contesto quantitativo al <?= $today ?></h2>
  <div class="stats">
    <div class="stat"><div class="stat-n"><?= number_format($totComments) ?></div><div class="stat-l">Commenti</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($totUsers) ?></div><div class="stat-l">Utenti social</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($totBans) ?></div><div class="stat-l">Ban attivi</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($totPages) ?></div><div class="stat-l">Pagine FB</div></div>
    <div class="stat"><div class="stat-n"><?= number_format($totAdmins) ?></div><div class="stat-l">Amministratori</div></div>
  </div>

  <!-- ── 3. Attività di trattamento ── -->
  <h2>3. Attività di trattamento</h2>

  <!-- T1 -->
  <div class="trattamento">
    <div class="t-head">
      <span class="t-num">Trattamento 1</span>
      <span class="t-name">Moderazione automatizzata e ibrida dei commenti Facebook</span>
    </div>
    <div class="t-body">
      <div class="t-cell">
        <div class="t-label">Finalità</div>
        <div class="t-val">Prevenire la pubblicazione di contenuti illeciti, offensivi, spam, scam e truffe finanziarie sulle Pagine Facebook gestite dal titolare.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Base giuridica</div>
        <div class="t-val">Art. 6.1.f GDPR — Legittimo interesse del titolare e degli utenti della Pagina. LIA disponibile su richiesta.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di interessati</div>
        <div class="t-val">Utenti Facebook che pubblicano commenti sulle Pagine gestite.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di dati</div>
        <div class="t-val">
          <ul>
            <li>Testo del commento</li>
            <li>Pseudonimo interno (HMAC-SHA256 dell'ID Facebook)</li>
            <li>Segnali di rischio aggregati (fascia età account, fascia follower, stato verifica)</li>
            <li>Numero di violazioni pregresse, stato ban</li>
          </ul>
        </div>
      </div>
      <div class="t-cell">
        <div class="t-label">Decisioni automatizzate (art. 22)</div>
        <div class="t-val">Sì. Pipeline a 3 livelli: Haiku → Sonnet → Umano. Ogni decisione contestabile via procedura di appello. Revisione umana in modalità <em>blind</em>: il moderatore vede solo uno pseudonimo interno, mai il nome reale Facebook.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Conservazione</div>
        <div class="t-val">Commenti approvati: 12 mesi. Commenti nascosti + log: 24 mesi. Eliminazione automatica.</div>
      </div>
      <div class="t-cell t-full">
        <div class="t-label">Destinatari / Responsabili</div>
        <div class="t-val">Anthropic PBC (AI, DPA + SCC); Meta Platforms Ireland (Graph API, SCC); moderatori umani interni.</div>
      </div>
    </div>
  </div>

  <!-- T2 -->
  <div class="trattamento">
    <div class="t-head">
      <span class="t-num">Trattamento 2</span>
      <span class="t-name">Gestione ban e storico violazioni (prevenzione recidiva)</span>
    </div>
    <div class="t-body">
      <div class="t-cell">
        <div class="t-label">Finalità</div>
        <div class="t-val">Prevenire comportamenti abusivi reiterati da parte dello stesso utente attraverso un sistema progressivo di sanzioni.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Base giuridica</div>
        <div class="t-val">Art. 6.1.f GDPR — Legittimo interesse. Proporzionato; i dati di ban vengono eliminati 24 mesi dopo l'ultima violazione.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di interessati</div>
        <div class="t-val">Utenti Facebook con violazioni accertate delle linee guida della Pagina.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di dati</div>
        <div class="t-val">
          <ul>
            <li>ID Facebook (piattaforma, non trasmesso a terzi)</li>
            <li>Contatore violazioni</li>
            <li>Tipo e durata del ban</li>
            <li>Categorie di violazione (JSON)</li>
            <li>Riferimento al log di moderazione che ha causato il ban</li>
          </ul>
        </div>
      </div>
      <div class="t-cell">
        <div class="t-label">Decisioni automatizzate</div>
        <div class="t-val">Parziale. Il ban temporaneo automatico scatta al superamento della soglia di recidiva configurata. Il ban definitivo richiede sempre revisione umana.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Conservazione</div>
        <div class="t-val">24 mesi dall'ultima violazione. Il ban può essere revocato anticipatamente da un amministratore su richiesta motivata.</div>
      </div>
      <div class="t-cell t-full">
        <div class="t-label">Destinatari</div>
        <div class="t-val">Solo moderatori e amministratori interni autorizzati. Nessuna comunicazione a terzi.</div>
      </div>
    </div>
  </div>

  <!-- T3 -->
  <div class="trattamento">
    <div class="t-head">
      <span class="t-num">Trattamento 3</span>
      <span class="t-name">Procedura di appello (contestazione della moderazione)</span>
    </div>
    <div class="t-body">
      <div class="t-cell">
        <div class="t-label">Finalità</div>
        <div class="t-val">Garantire all'interessato il diritto di contestare le decisioni automatizzate e richiedere revisione umana, come previsto dall'art. 22.3 GDPR.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Base giuridica</div>
        <div class="t-val">Art. 6.1.b GDPR — Misure precontrattuali su richiesta dell'interessato. Art. 22.3 — Obbligo di garantire la revisione umana.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di interessati</div>
        <div class="t-val">Utenti Facebook che contestano una decisione di moderazione ricevuta.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di dati</div>
        <div class="t-val">
          <ul>
            <li>Token di appello firmato (HMAC, scade 30 giorni)</li>
            <li>Testo dell'appello inviato dall'utente</li>
            <li>Decisione del moderatore umano e note</li>
          </ul>
        </div>
      </div>
      <div class="t-cell">
        <div class="t-label">Decisioni automatizzate</div>
        <div class="t-val">No. Ogni appello è gestito esclusivamente da un moderatore umano.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Conservazione</div>
        <div class="t-val">Token: 30 giorni. Record appello: 24 mesi (coerente con il log di moderazione associato).</div>
      </div>
      <div class="t-cell t-full">
        <div class="t-label">Destinatari</div>
        <div class="t-val">Solo moderatori e amministratori interni. Nessuna comunicazione a terzi.</div>
      </div>
    </div>
  </div>

  <!-- T4 -->
  <div class="trattamento">
    <div class="t-head">
      <span class="t-num">Trattamento 4</span>
      <span class="t-name">Accesso e autenticazione degli amministratori</span>
    </div>
    <div class="t-body">
      <div class="t-cell">
        <div class="t-label">Finalità</div>
        <div class="t-val">Consentire ai moderatori e amministratori autorizzati di accedere alla piattaforma di moderazione in modo sicuro e tracciabile.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Base giuridica</div>
        <div class="t-val">Art. 6.1.b GDPR — Esecuzione del contratto di lavoro/collaborazione con il personale autorizzato.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di interessati</div>
        <div class="t-val">Dipendenti, collaboratori o incaricati del titolare con ruolo di moderatore, supervisore o amministratore.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di dati</div>
        <div class="t-val">
          <ul>
            <li>Email aziendale</li>
            <li>Token SSO (Microsoft Azure AD / Entra ID)</li>
            <li>JWT di sessione</li>
            <li>Log di accesso (IP, user-agent, timestamp)</li>
            <li>Decisioni di moderazione e note</li>
          </ul>
        </div>
      </div>
      <div class="t-cell">
        <div class="t-label">Decisioni automatizzate</div>
        <div class="t-val">No.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Conservazione</div>
        <div class="t-val">Log di accesso: 12 mesi. Log di moderazione: 24 mesi. Dati account: per la durata del rapporto + 6 mesi.</div>
      </div>
      <div class="t-cell t-full">
        <div class="t-label">Destinatari</div>
        <div class="t-val">Microsoft Corporation (Azure AD SSO, SCC + Data Protection Addendum). Nessun altro destinatario esterno.</div>
      </div>
    </div>
  </div>

  <!-- T5 -->
  <div class="trattamento">
    <div class="t-head">
      <span class="t-num">Trattamento 5</span>
      <span class="t-name">Miglioramento continuo del sistema (feedback umano)</span>
    </div>
    <div class="t-body">
      <div class="t-cell">
        <div class="t-label">Finalità</div>
        <div class="t-val">Migliorare l'accuratezza del sistema di moderazione AI analizzando le decisioni umane che correggono o confermano quelle automatiche.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Base giuridica</div>
        <div class="t-val">Art. 6.1.f GDPR — Legittimo interesse al miglioramento del servizio. I dati sono pseudonimizzati e mai condivisi con terzi per training.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Categorie di dati</div>
        <div class="t-val">Log di moderazione aggregati (decisione AI vs decisione umana, categorie, confidenza). Nessun dato identificativo dell'interessato.</div>
      </div>
      <div class="t-cell">
        <div class="t-label">Conservazione</div>
        <div class="t-val">Coerente con la conservazione del log di moderazione associato (24 mesi).</div>
      </div>
      <div class="t-cell t-full">
        <div class="t-label">Destinatari</div>
        <div class="t-val">Solo uso interno. I dati non vengono trasmessi ad Anthropic o ad altri terzi per finalità di addestramento dei modelli AI.</div>
      </div>
    </div>
  </div>

  <!-- ── 4. Responsabili esterni (sub-processor) ── -->
  <h2>4. Responsabili del trattamento esterni (art. 28 GDPR)</h2>
  <table>
    <thead>
      <tr>
        <th>Soggetto</th>
        <th>Ruolo</th>
        <th>Paese</th>
        <th>Garanzie trasferimento</th>
        <th>Trattamenti coinvolti</th>
      </tr>
    </thead>
    <tbody>
      <tr>
        <td><strong>Anthropic PBC</strong><br>340 Pine St, Suite 323, San Francisco CA 94104</td>
        <td>Responsabile del trattamento (Data Processor)</td>
        <td>USA</td>
        <td>DPA + Clausole Contrattuali Standard (SCC) — Decisione UE 2021/914. I dati API non sono usati per training.</td>
        <td>T1 (analisi AI commenti)</td>
      </tr>
      <tr>
        <td><strong>Meta Platforms Ireland Ltd.</strong><br>4 Grand Canal Square, Dublino</td>
        <td>Titolare autonomo per la piattaforma Facebook; fornitore API per la Pagina</td>
        <td>UE / USA</td>
        <td>SCC; EU-US Data Privacy Framework; DPA Meta per Business</td>
        <td>T1, T2, T3 (accesso Pagina e commenti)</td>
      </tr>
      <tr>
        <td><strong>Microsoft Corporation</strong><br>One Microsoft Way, Redmond WA 98052</td>
        <td>Responsabile del trattamento (Identity Provider)</td>
        <td>UE / USA</td>
        <td>SCC + Microsoft Data Protection Addendum (DPA)</td>
        <td>T4 (autenticazione SSO amministratori)</td>
      </tr>
      <tr>
        <td><strong>Hosting provider</strong><br>(cPanel shared hosting)</td>
        <td>Responsabile del trattamento (infrastruttura)</td>
        <td>Da verificare</td>
        <td>Contratto di hosting con clausole DPA — verificare con il provider specifico</td>
        <td>Tutti</td>
      </tr>
    </tbody>
  </table>

  <!-- ── 5. Misure di sicurezza ── -->
  <h2>5. Misure di sicurezza (art. 32 GDPR)</h2>
  <div class="sicurezza">
    <div class="sic-item"><strong>Cifratura in transito</strong>TLS 1.2+ su tutte le comunicazioni API (Anthropic, Meta, Microsoft).</div>
    <div class="sic-item"><strong>Pseudonimizzazione</strong>ID Facebook sostituito con hash HMAC-SHA256 prima di qualsiasi trasmissione all'AI.</div>
    <div class="sic-item"><strong>Autenticazione</strong>SSO Azure AD con MFA per gli amministratori. JWT firmato per le sessioni.</div>
    <div class="sic-item"><strong>Controllo accessi</strong>Principio del minimo privilegio: ruoli admin / moderator / supervisor con permessi differenziati.</div>
    <div class="sic-item"><strong>Audit log</strong>Ogni decisione di moderazione è registrata con timestamp, utente e modello AI utilizzato.</div>
    <div class="sic-item"><strong>Conservazione limitata</strong>Eliminazione automatica programmata per ogni categoria di dati secondo i periodi definiti.</div>
    <div class="sic-item"><strong>Dev mode</strong>Modalità sviluppo che impedisce azioni reali su Facebook durante i test.</div>
    <div class="sic-item"><strong>Hashing contenuti</strong>SHA-256 sul contenuto dei commenti per riconoscere le ripubblicazioni identiche e riapplicare la decisione di moderazione già adottata.</div>
    <div class="sic-item"><strong>Token firmati</strong>Token di appello HMAC con scadenza 30 giorni — non falsificabili senza la chiave APP_SECRET.</div>
  </div>

  <!-- ── 6. Diritti degli interessati ── -->
  <h2>6. Procedure per l'esercizio dei diritti degli interessati</h2>
  <table>
    <thead>
      <tr><th>Diritto</th><th>Modalità di esercizio</th><th>Termine di risposta</th></tr>
    </thead>
    <tbody>
      <tr>
        <td>Accesso (art. 15)</td>
        <td>Richiesta via email a <?= htmlspecialchars($orgEmail, ENT_QUOTES) ?></td>
        <td>30 giorni (prorogabili a 90)</td>
      </tr>
      <tr>
        <td>Rettifica (art. 16)</td>
        <td>Richiesta via email</td>
        <td>30 giorni</td>
      </tr>
      <tr>
        <td>Cancellazione (art. 17)</td>
        <td>Richiesta via email — valutazione caso per caso rispetto agli obblighi di conservazione</td>
        <td>30 giorni</td>
      </tr>
      <tr>
        <td>Opposizione (art. 21)</td>
        <td>Richiesta via email — il titolare valuta e può rifiutare per motivi legittimi cogenti</td>
        <td>30 giorni</td>
      </tr>
      <tr>
        <td>Non essere soggetto a decisione automatizzata (art. 22)</td>
        <td>Link di appello incluso nella notifica di moderazione (valido 30 giorni) oppure email</td>
        <td>Immediato (appello) / 30 giorni (email)</td>
      </tr>
      <tr>
        <td>Portabilità (art. 20)</td>
        <td>Richiesta via email — dati forniti in formato JSON</td>
        <td>30 giorni</td>
      </tr>
    </tbody>
  </table>

  <!-- ── Footer ── -->
  <div class="doc-footer">
    <span>
      <?= htmlspecialchars($orgName, ENT_QUOTES) ?> —
      Registro generato automaticamente da Social Moderation Hub il <?= $today ?>
    </span>
    <span class="no-print">
      <a href="javascript:window.print()" style="color:#555;text-decoration:none">🖨 Stampa / Salva PDF</a>
    </span>
  </div>

</div>
</body>
</html>