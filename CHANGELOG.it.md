# Changelog

[🇬🇧 English](CHANGELOG.md) · 🇮🇹 Italiano

Tutte le modifiche rilevanti del progetto sono documentate in questo file.  
Formato basato su [Keep a Changelog](https://keepachangelog.com/it-IT/1.0.0/).  
Il progetto segue il [Semantic Versioning](https://semver.org/lang/it/).

---

## [Unreleased]

### Added
- **Contesto della discussione nel dettaglio** — "Apri il contesto della discussione" mostra il riassunto del post,
  il commento padre, fino a 3 commenti precedenti e 3 successivi sul post e le risposte, con il loro stato
  (visibile / nascosto / in coda); gli autori sono solo pseudonimi interni (`Utente #ID`). L'id del padre viene
  salvato dal webhook (migrazione 013) e chiesto a Facebook per i commenti più vecchi. `GET /api/comments/{id}/context`.
- **Contesto del post per l'AI (Pro, `post_context`)** — al primo commento su un post Haiku lo riassume
  (testo, link condiviso, immagine) in due righe brevi (max 1000 caratteri); il riassunto accompagna ogni
  commento di quel post e compare, troncato, nel dettaglio del commento. Non bloccante: un errore non
  ritarda mai la moderazione. Interruttore in Impostazioni → AI. Tabella `post_contexts` (migrazione 009).
- **Training AI (Advanced / Enterprise, `ai_training`)** — con la raccolta attiva i moderatori scrivono una
  nota ogni volta che una decisione rinforza o corregge il verdetto dell'AI (decisioni in coda, nascondere un
  commento approvato, ripristinarne uno nascosto, decidere un ricorso); le note si possono aggiungere anche
  dopo dal menù ⋯. Dopo N note la raccolta si ferma e Sonnet propone una nuova versione del prompt, inattiva,
  da rivedere. Funziona anche in modalità sviluppo. Tabelle `ai_training_sessions`, `ai_training_notes`
  (migrazione 010).
- **Rianalisi dei commenti modificati (Pro, `edit_recheck`)** — un commento approvato o in coda modificato
  dall'autore viene rivalutato dopo un'attesa configurabile (ogni modifica fa ripartire il conto), solo se le
  parole sono davvero cambiate (maiuscole, punteggiatura, spazi e accenti non contano). Le impostazioni
  scelgono quali stati controllare e se un problema nasconde il commento o lo manda in coda; una decisione
  di un moderatore non viene mai ribaltata in automatico. Richiede il cron `bin/recheck-edits.php` ogni
  minuto (migrazioni 011, 012).
- **Rivalutazione manuale (admin)** — "Rivaluta con AI" nel menù ⋯ della coda di revisione e delle liste
  Approvati e Nascosti; rilegge il testo attuale da Facebook e rifà tutta la pipeline.
- **Utilizzo ed efficacia del prompt (Enterprise, `prompt_stats`)** — per ogni versione del prompt:
  valutazioni, decisioni autonome poi ribaltate da un moderatore (troppo permissive / troppo severe),
  escalation e come sono state risolte, confidenza media e tasso di errore per fascia di confidenza; filtro
  per dati di produzione / sviluppo. Quattro card di riepilogo nella pagina pubblica della policy (e
  `statistics` nel suo JSON).
- **Notifiche push e PWA installabile** — Web Push per revisione, segnalazione, ricorso e prova, con prefisso
  breve "Revisione: / Segnalazione: / Ricorso:"; service worker con pagina offline; barra a tab in basso.
- **Reset password da admin** — gli admin possono reimpostare la password di un altro utente locale con una
  password temporanea monouso (cambio obbligatorio al primo accesso); tracciato in audit, mai sul proprio account.
- **Marchio personalizzato** — l'icona caricata diventa logo del menù, favicon e icona della PWA (con fallback).
- **Soglie AI per pagina** — slider e percentuali intere, interruttori per fact-check / whataboutism.
- **Debug webhook (`advanced_audit`)** — vista diagnostica degli eventi Meta; ora salva subito lo stato
  "in elaborazione" e registra le esecuzioni interrotte (timeout, errori fatali) invece di lasciare il
  risultato vuoto.
- **Diritti dell'interessato — accesso/export/anonimizzazione (artt. 15/17/20 GDPR)** —
  nuovo pannello admin in Impostazioni → Privacy: ricerca utente per ID Facebook, ID
  interno o token di appello; export JSON completo dei dati collegati; anonimizzazione
  irreversibile con doppia conferma e motivazione obbligatoria (mai una DELETE fisica,
  stesso schema di anonimizzazione di `RetentionService`). Ogni azione è tracciata in
  `gdpr_audit_log` (accountability art. 5.2). Endpoint `GET/POST /api/gdpr/*`,
  `GdprService`/`GdprController`, admin-only.
- **LIA — Legitimate Interest Assessment (art. 6.1.f GDPR)** — documento di
  bilanciamento degli interessi generato dinamicamente come la DPIA, con i tre test
  standard EDPB/WP29 (purpose/necessity/balancing) e le statistiche reali
  dell'installazione (soglia recidiva, durate ban, tasso di accoglimento appelli).
  Include il bilanciamento sui minori ex Recital 38. Endpoint `GET /api/lia`,
  `ModerationController::exportLia()`, template `public/lia.php`, admin-only.
- **Migrazioni SQL automatiche al deploy** — il webhook di auto-deploy
  (`/webhook/github`) applica ora da solo le `.sql` pendenti in
  `database/migrations/` dopo ogni `git pull`, tracciandole in una nuova tabella
  `schema_migrations`. Le migrazioni storiche non idempotenti (002, 003) vengono
  riconosciute via controllo diretto della colonna che introducono, invece di essere
  ri-eseguite su installazioni che le hanno già. Uso manuale: `php bin/migrate.php`.
- **Canali di release `beta` (test) / `main` (clienti)** — `beta` è il canale di
  sviluppo/test con auto-deploy verso l'installazione di staging; `main` (branch di
  default) è il canale per le installazioni clienti, promosso con un merge
  fast-forward quando `beta` è verificato. Documentato in
  `docs/deployment-security.md` §9.
- **Coda di revisione anonimizzata** — i moderatori umani vedono solo uno pseudonimo interno
  (`Utente #ID`) nella coda di revisione, mai il nome reale Facebook. Il `display_name` è escluso
  dal SELECT delle API `/api/queue` e `/api/queue/reportable` (mai trasmesso al client in contesto
  di revisione). Elimina il pregiudizio basato sull'identità dell'autore e minimizza ulteriormente
  l'impatto GDPR del trattamento.
- **DPIA export (art. 35 GDPR)** — documento di Valutazione d'Impatto sulla Protezione dei Dati
  scaricabile dalle Impostazioni → Privacy Policy. Il documento si genera dinamicamente con i dati
  reali dell'installazione (titolare, retention configurata, statistiche live) e copre: descrizione
  del trattamento, base giuridica, fattori di rischio WP248 (8 criteri), identificazione 8 rischi
  con livello iniziale/residuo, misure di mitigazione tecniche e organizzative, spazio parere DPO,
  consultazione preventiva art. 36, piano di riesame e blocco firme. Endpoint `GET /api/dpia`,
  metodo `ModerationController::exportDpia()`, template `public/dpia.php`.
- **DPIA — sezione data breach (artt. 33–34 GDPR)** — R3 espansa con: tabella 6 scenari
  di breach (credenziali compromesse, SQL injection, server compromise, insider threat,
  backup in chiaro, esposizione accidentale dashboard) ciascuno con probabilità, impatto
  e obbligo di notifica; misure di rilevamento; procedura di risposta a 6 fasi con
  tempistiche obbligatorie (h0 contenimento → 24h valutazione → 72h notifica Garante
  art. 33 → notifica interessati art. 34 → recovery → registro interno incidenti).

### Changed
- **Coda di revisione e liste dei commenti con lo stesso layout** — stesso ordine dei badge (chi ha deciso ·
  verdetto AI · segnali/categorie · stati), note sotto il testo, menù ⋯ nell'angolo (anche su mobile), tag
  allineati a destra; verdetto AI in un solo badge colorato per esito ("AI: Approvato · 97%"), decisioni
  manuali con lo stesso stile ("Nascosto manualmente"); i nomi dei moderatori li vedono solo supervisori e admin.
- "Policy AI" rinominata **Prompt AI**; interruttori al posto delle caselle di controllo nelle Impostazioni.
- I badge della barra laterale scompaiono quando il conteggio è 0.

### Fixed
- Dopo una modifica, nel dettaglio la valutazione AI veniva sostituita dalla riga di sistema "modificato".
- Le note di addestramento venivano saltate del tutto con la modalità sviluppo attiva.
- Mobile: l'overflow del documento faceva scorrere la barra a tab; le tab delle impostazioni scorrevano in
  verticale; l'intestazione del dettaglio era trasparente sopra l'area del notch.
- **Privacy policy — §5.4 ban automatico** — non parlava più di "ban definitivo con
  revisione umana preventiva" (concetto inesistente nel codice: `BanService` non
  applica mai ban permanenti, e il ban alla soglia di recidiva è completamente
  automatico). Riformulato: ban sempre temporaneo, contestabile ex-post via appello.
- **Privacy policy — §11 minori** — non negava più una presenza prevedibile
  ("non destinata a raccogliere dati di minori... cancellazione immediata se
  scoperti") su una pagina Facebook pubblica dove l'età non è verificabile.
  Riformulato per riconoscere la prevedibilità e motivare le tutele già esistenti
  (blind review, nessun ban alla prima violazione, appello sempre disponibile) come
  risposta proporzionata, ex Recital 38 GDPR.
- **Privacy policy pubblica e registro trattamenti** — rimossi tutti i riferimenti a
  "rimozione/removal" dei commenti; la terminologia corretta è "nascondimento temporaneo".
  Aggiornati: `public/privacy.php` (sezioni IT ed EN §5, §7), `public/registro.php` (T1
  conservazione), `public/dpia.php` (fail-safe). In linea con la policy editoriale del sistema:
  l'azione preferita è il nascondimento con possibilità di appello, non la cancellazione.

### Security
- **Accesso diretto ai documenti di compliance** — `public/.htaccess` instradava a
  `index.php` solo se il file richiesto non esisteva già su disco. `dpia.php`,
  `registro.php` e `lia.php` esistono come file reali, quindi una richiesta diretta
  li serviva bypassando del tutto l'autenticazione JWT applicata alle route
  `/api/dpia`, `/api/registro-trattamenti`, `/api/lia`. Forzati anch'essi attraverso
  `index.php`, come già avveniva per `dashboard.html`.

### Pianificato (edizione Community)
- Notifiche Slack / email quando la coda umana supera una soglia
- Supporto a Instagram (stessa pipeline via Meta Graph API)

### Pianificato (livello Pro attuale)
- Rilevamento flame e blocco del post
- Rilevamento di bot e campagne coordinate
- Integrazione dei metadati dell'account Meta (età account, follower)
- Export programmato dei report (PDF/CSV)
- Modalità test del prompt

### In progettazione — livello Advanced (in arrivo, licenza separata)
Un livello superiore per pagine ad alto volume, centrato su predizione proattiva del rischio e intelligence
editoriale. Queste funzioni richiederanno una chiave di licenza separata al rilascio.
- **Dual confidence scoring** — accanto alla "probabilità di violazione" l'AI darà un punteggio di
  "probabilità di valore genuino" a ogni commento, così la dashboard potrà ordinare per entrambi gli assi e
  far emergere i commenti di qualità da amplificare.
- **Analisi per argomento e alert proattivi per post** — post etichettati automaticamente per argomento;
  tassi di engagement e di incidenti per argomento; punteggio di rischio previsto dai pattern storici quando
  viene pubblicato un nuovo post.

---

## [1.5.0] — 2026-05-29
### Added
- **Whataboutism detection (PRO)** — rilevamento della fallacia retorica di
  deflessione ("e allora le foibe?", "ma X ha fatto peggio?") con bozza di
  risposta editoriale che riporta in-topic, parallelo al fact-check ma senza
  ricerca web. Auto-pubblicazione sopra soglia (default `0.95`) con verifica
  fredda di una seconda call Sonnet; sotto soglia → coda umana con draft
  pronto. Nuovi campi su `moderation_log`: `ai_whataboutism_suggested`,
  `ai_whataboutism_draft`, `ai_whataboutism_confidence`,
  `ai_whataboutism_latency_ms`. Nuovo `final_action` value
  `auto_whataboutism_replied`. Toggle per-pagina `whataboutism_enabled`.
  Setting globale `whataboutism_auto_publish_threshold`. Collision policy:
  se anche fact-check è attivo, fact-check ha priorità e whataboutism va a
  revisione umana senza auto-publish.
- **AI signal badges nei commenti approvati e nascosti** — chip dedicati
  ("🔍 Fact-check", "↩️ Whataboutism") indipendenti dalla decisione finale,
  per vedere COSA aveva segnalato l'AI anche dopo override umano.
- **Filtro per signals AI** — query param `signal` su `/api/comments/approved`,
  `/api/comments/hidden`, `/api/bans/comments`. Filter bar nelle 2 schermate
  combinabile con il filtro `decided_by`.
- **Filtro per categoria AI** — query param `category` (con
  `JSON_CONTAINS` sanitized) e dropdown UI popolato da `CAT_LABELS`.
- **Chip colorati per-categoria** — palette tematica (truffe rossi, spam
  arancio, odio magenta, disinformazione viola) via nuovo helper
  `categoryChip(cat)` in `config.js`. Refactor di 9 chiamate.
- **Contatore Sonnet sub-calls** — nuova metrica `sonnet_subcalls`
  (fact_check + whataboutism) in `/api/stats`. Cattura le call Sonnet che
  NON aggiornano lo stage del log perché parte di flussi sub-pipeline.
- **Pro wall counter** — la schermata Segnalazioni lockata mostra il numero
  di segnalazioni accumulate via `loadStats()`. Indica "safety preservata
  → Pro per gestirle".
- **Hidden queue include reportable in attesa** — i commenti
  `escalated_reportable` appaiono ora anche nella schermata Commenti
  nascosti, marcati con badge "⏳ in attesa valutazione legale" distinto
  dal generico "⚠️ segnalabile".
- **Badge decider esplicito** — quando manca human_user_id, il chip mostra
  "Nascosto dall'AI" (era vuoto).

### Changed
- **Pro/Free alignment** — `reportable_queue` e `advanced_stats` ora sono
  feature Pro effettivamente gated dal license server (prima erano dichiarate
  Pro nella landing ma libere nel codice). UI nasconde via Pro wall.
- **Stats split** — `/api/stats` ora restituisce counters base sempre, e
  i campi chart (`by_stage`, `by_ai_decision`, `sonnet_subcalls`, 30d trends)
  solo con feature `advanced_stats`.
- **Landing pricing card unificate** — 3 card (Free / Pro / Advanced) con
  stessa anatomia: label → price → period → desc → divider → features
  → soon → divider → CTA. Padding ridotto, separator gradient per piano.
- **Pricing Advanced** — €29.90/mese o €299/anno (sconto annuale 17%).
  Risponde al toggle billing insieme a Pro.
- **Slack/email + Instagram** spostate da "Pro Coming soon" a "Community
  Coming soon". Razionale: utility base, non gating editoriale.
- **README + landing** — Pro/Free distinct sections; Newsroom rinominato
  Advanced ovunque; license feature keys NON pubblicate in docs pubblici
  (rimangono solo in `LicenseService::KNOWN_FEATURES`).
- **URL nei reply** — rimossi gli avvisi "no link" dai prompt fact-check e
  whataboutism + dalla UI dei modal: Facebook accetta link nei reply Pagina.
- **Tracking commit identity** — git config locale corretto a
  `checco83casali <checco83@gmail.com>` (era misto con global config aziendale).

### Fixed
- **Stats `by_stage` rotto** — il cast `(array)` su Illuminate Collection
  esponeva proprietà interne (`*items`, `*escapeWhenCastingToString`) come
  chiavi nel JSON, mascherando i dati reali. Sostituito con `->all()` +
  `array_map('intval')`. Stesso fix preventivo su `by_ai_decision`.
- **Prezzi invisibili nella landing** — selettore CSS `.plan-price span`
  schiacciava anche `<span id="price-display">` e `<span id="adv-price-display">`
  che contengono il prezzo intero su Pro/Advanced. Ristretto a `.price-suffix`.

### Migration required
- **`002_whataboutism.sql`** — ALTER su `moderation_log` (+4 colonne whataboutism),
  `page_settings` (+toggle), `app_settings` (+threshold). Da eseguire una
  sola volta su installazioni esistenti.

---

## [1.0.0] — 2025-01-01

### Added
- **Pipeline di moderazione AI**: Claude Haiku → Sonnet → escalation umana
- **Integrazione Meta Graph API**: webhook delle pagine Facebook, acquisizione ed eliminazione dei commenti
- **Autenticazione OAuth2**: Google, Meta (Facebook), Microsoft
- **Sistema di ban**: ban a livello di commento e di utente con tracciamento della recidiva (temporaneo → permanente)
- **Gestione delle policy**: system prompt versionati, una sola policy attiva alla volta
- **Coda di revisione umana**: dashboard con priorità e contesto AI per ogni commento
- **Dashboard admin**: SPA a tema scuro (HTML/JS, senza framework)
- **Widget incorporabile**: `connect.js` per collegare la pagina Facebook
- **Installer da riga di comando**: `install.php` con configurazione guidata
- **Audit trail completo**: ogni decisione AI e umana registrata con modello, confidenza, latenza
- **Export dei dati per l'apprendimento**: decisioni di ban umane disponibili come dati strutturati per affinare la policy
- **Licenza MIT**

[Unreleased]: https://github.com/checco83casali/social-moderation-hub/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/checco83casali/social-moderation-hub/compare/v1.0.0...v1.5.0
[1.0.0]: https://github.com/checco83casali/social-moderation-hub/releases/tag/v1.0.0
