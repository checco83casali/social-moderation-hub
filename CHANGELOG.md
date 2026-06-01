# Changelog

All notable changes to this project will be documented in this file.  
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Added
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

### Fixed
- **Privacy policy pubblica e registro trattamenti** — rimossi tutti i riferimenti a
  "rimozione/removal" dei commenti; la terminologia corretta è "nascondimento temporaneo".
  Aggiornati: `public/privacy.php` (sezioni IT ed EN §5, §7), `public/registro.php` (T1
  conservazione), `public/dpia.php` (fail-safe). In linea con la policy editoriale del sistema:
  l'azione preferita è il nascondimento con possibilità di appello, non la cancellazione.

### Planned (Community edition)
- Slack / email notifications when human queue exceeds threshold
- Instagram support (same pipeline via Meta Graph API)

### Planned (current Pro tier)
- Flame detection & post lock
- Bot & coordinated campaign detection
- Meta account metadata integration (account age, follower count)
- Scheduled report export (PDF/CSV)
- Policy test mode

### In design — Advanced tier (launching soon, separate license)
A higher tier for high-volume pages, focused on proactive risk-prediction
and editorial intelligence. These features are in design and will require a
separate license key when released.

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

- **Dual confidence scoring** — alongside the current "probability of violation"
  the AI will also score a "probability of genuine value" for each comment, so
  the dashboard can sort by either axis. Useful to surface high-quality but
  low-engagement comments worth amplifying, not just the ones to moderate.
- **Contextual grounding** — the first incoming comment on each new post
  triggers an extractive summary of the post itself; that summary is then
  attached as context to every subsequent comment sent to Claude, so the
  moderation prompt knows what the post is about (current pipeline moderates
  comments in isolation from the post body).
- **Topic-aware analytics & proactive per-post alerts** — built on Contextual
  Grounding: posts get auto-tagged by topic (sport, culture, environment,
  politics, foreign affairs, etc.). The dashboard then shows engagement and
  incident rates per topic, and when a new post is published the system
  predicts a risk score based on historical patterns ("this topic averages 40%
  problematic comments over its lifetime — pre-alert"), giving moderators
  a heads-up before the wave of comments arrives.

---

## [1.0.0] — 2025-01-01

### Added
- **AI moderation pipeline**: Claude Haiku → Sonnet → Human escalation
- **Meta Graph API integration**: Facebook page webhooks, comment capture and deletion
- **OAuth2 authentication**: Google, Meta (Facebook), Microsoft
- **Ban system**: comment-level and user-level bans with recidivism tracking (temp → permanent)
- **Policy management**: versioned system prompts, one active policy at a time
- **Human review queue**: prioritised dashboard with AI context per comment
- **Admin dashboard**: dark-theme SPA (HTML/JS, no framework dependency)
- **Embeddable widget**: `connect.js` for Facebook page connection
- **CLI installer**: `install.php` with guided setup
- **Full audit trail**: every AI and human decision logged with model, confidence, latency
- **Learning data export**: human ban decisions available as structured data for policy refinement
- **MIT License**

[Unreleased]: https://github.com/checco83casali/social-moderation-hub/compare/v1.5.0...HEAD
[1.5.0]: https://github.com/checco83casali/social-moderation-hub/compare/v1.0.0...v1.5.0
[1.0.0]: https://github.com/checco83casali/social-moderation-hub/releases/tag/v1.0.0
