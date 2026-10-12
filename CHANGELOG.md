# Changelog

🇬🇧 English · [🇮🇹 Italiano](CHANGELOG.it.md)

All notable changes to this project will be documented in this file.  
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

### Added
- **Post context for the AI (Pro, `post_context`)** — at the first comment on a post Haiku summarises it
  (text, shared link, image) in two short lines (max 1000 characters); the summary is sent with every
  comment of that post and shown, truncated, in the comment detail. Non-blocking: failures never delay
  moderation. Toggle in Settings → AI. Table `post_contexts` (migration 009).
- **AI training (Advanced / Enterprise, `ai_training`)** — with the collection on, moderators write a note
  whenever a decision reinforces or corrects the AI verdict (queue decisions, hiding an approved comment,
  restoring a hidden one, deciding an appeal); notes can also be added afterwards from the ⋯ menu. After N
  notes collection stops and Sonnet proposes a new, inactive prompt version to review. Works in development
  mode too. Tables `ai_training_sessions`, `ai_training_notes` (migration 010).
- **Edited-comment re-analysis (Pro, `edit_recheck`)** — an approved or queued comment edited by its author
  is re-evaluated after a configurable quiet delay (each edit restarts the wait), only if the words really
  changed (case, punctuation, spaces and accents are ignored). Settings choose which states to watch and
  whether a problem hides the comment or queues it; a moderator's decision is never overturned
  automatically. Needs the `bin/recheck-edits.php` cron every minute (migrations 011, 012).
- **Manual re-evaluation (admins)** — "Re-evaluate with AI" in the ⋯ menu of the review queue, Approved and
  Hidden lists; rereads the current text from Facebook and runs the full pipeline again.
- **Prompt usage & effectiveness (Enterprise, `prompt_stats`)** — per prompt version: evaluations, autonomous
  decisions later overturned by a moderator (too permissive / too strict), escalations and how they were
  resolved, average confidence and error rate per confidence band; filter for production / development data.
  Four summary cards on the public policy page (and `statistics` in its JSON).
- **Push notifications and installable PWA** — Web Push for review, report, appeal and test events with a
  short "Review: / Report: / Appeal:" prefix; service worker with offline page; bottom tab bar.
- **Admin password reset** — admins can reset another local user's password with a one-time temporary
  password (forced change at next login); audited, never on their own account.
- **Custom branding** — an uploaded icon becomes the menu logo, favicon and PWA icon (default fallback).
- **Per-page AI thresholds** — slider and integer percentages, toggles for fact-check / whataboutism.
- **Webhook debug (`advanced_audit`)** — diagnostic view of Meta events; now stores an "in progress" state
  and records interrupted runs (timeouts, fatal errors) instead of leaving the result empty.
- **GDPR data-subject rights — access/export/anonymisation (arts. 15/17/20 GDPR)** — new admin panel in
  Settings → Privacy: search by Facebook ID, internal ID or appeal token; full JSON export of linked data;
  irreversible anonymisation with double confirmation and mandatory reason (never a physical DELETE, same
  scheme as `RetentionService`). Every action is logged in `gdpr_audit_log` (accountability, art. 5.2).
  `GET/POST /api/gdpr/*`, `GdprService`/`GdprController`, admin-only.
- **LIA — Legitimate Interest Assessment (art. 6.1.f GDPR)** — balancing document generated dynamically like
  the DPIA, with the three standard EDPB/WP29 tests (purpose/necessity/balancing) and the installation's real
  statistics. Includes the balancing on minors (Recital 38). `GET /api/lia`, template `public/lia.php`.
- **Automatic SQL migrations on deploy** — the auto-deploy webhook (`/webhook/github`) now applies pending
  `.sql` files in `database/migrations/` after every `git pull`, tracked in `schema_migrations`. Historic
  non-idempotent migrations (002, 003) are detected by checking the column they introduce. Manual use:
  `php bin/migrate.php`.
- **`beta` (test) / `main` (customers) release channels** — `beta` auto-deploys to staging; `main` is promoted
  with a fast-forward merge once `beta` is verified. See `docs/deployment-security.md` §9.
- **Anonymised review queue** — human moderators only see an internal pseudonym (`User #ID`), never the real
  Facebook name; `display_name` is excluded from `/api/queue` and `/api/queue/reportable`.
- **DPIA export (art. 35 GDPR)** — generated dynamically from Settings → Privacy Policy with the installation's
  real data (controller, retention, live statistics); covers processing, legal basis, WP248 risk factors,
  8 risks with initial/residual level, mitigations, DPO opinion, art. 36 consultation and signature block.
  `GET /api/dpia`, template `public/dpia.php`.
- **DPIA — data breach section (arts. 33–34 GDPR)** — 6 breach scenarios with likelihood, impact and notification
  duty; detection measures; 6-phase response procedure with the mandatory timings.

### Changed
- **Review queue and comment lists share one layout** — same order of badges (who decided · AI verdict ·
  signals/categories · states), notes under the comment text, ⋯ menu in the corner (also on mobile), tags
  aligned to the right; AI verdict shown as one badge coloured by outcome ("AI: Approved · 97%"), manual
  decisions with the same style ("Hidden manually"); moderator names visible only to supervisors and admins.
- "Policy AI" renamed **Prompt AI**; toggles instead of checkboxes in Settings.
- Sidebar badges hidden when the count is 0.

### Fixed
- The AI evaluation of a comment was replaced in the detail view by the "edited" system log entry after an edit.
- Training notes were skipped entirely while development mode was on.
- Mobile: document overflow made the tab bar scroll; settings tabs scrolled vertically; the detail sheet header
  was transparent over the notch area.
- **Privacy policy — §5.4 automatic ban** — no longer mentions a "permanent ban with prior human review" (a
  concept that does not exist in the code: `BanService` never applies permanent bans). Reworded: bans are always
  temporary and contestable afterwards by appeal.
- **Privacy policy — §11 minors** — no longer denies a foreseeable presence on a public Facebook page where age
  cannot be verified; now acknowledges it and justifies the existing safeguards (blind review, no ban on first
  violation, appeal always available) as a proportionate response under Recital 38 GDPR.
- **Public privacy policy and processing register** — removed every reference to comment "removal"; the correct
  term is "temporary hiding" (`public/privacy.php`, `public/registro.php`, `public/dpia.php`).

### Security
- **Direct access to compliance documents** — `public/.htaccess` only routed to `index.php` when the requested
  file did not exist on disk. `dpia.php`, `registro.php` and `lia.php` exist as real files, so a direct request
  served them bypassing the JWT authentication of `/api/dpia`, `/api/registro-trattamenti`, `/api/lia`. They are
  now forced through `index.php` like `dashboard.html`.

### Planned (Community edition)
- Slack / email notifications when the human queue exceeds a threshold
- Instagram support (same pipeline via Meta Graph API)

### Planned (current Pro tier)
- Flame detection & post lock
- Bot & coordinated campaign detection
- Meta account metadata integration (account age, follower count)
- Scheduled report export (PDF/CSV)
- Policy test mode

### In design — Advanced tier (launching soon, separate license)
A higher tier for high-volume pages, focused on proactive risk prediction and editorial intelligence. These
features will require a separate license key when released.
- **Dual confidence scoring** — alongside the "probability of violation" the AI will score a "probability of
  genuine value" for each comment, so the dashboard can sort by either axis and surface high-quality comments
  worth amplifying.
- **Topic-aware analytics & proactive per-post alerts** — posts auto-tagged by topic; engagement and incident
  rates per topic; a risk score predicted from historical patterns when a new post is published.

---

## [1.5.0] — 2026-05-29

### Added
- **Whataboutism detection (PRO)** — detects the rhetorical deflection fallacy ("what about the foibe?", "but X
  did worse?") with an editorial draft reply that brings the discussion back on topic; parallel to fact-check but
  without web search. Auto-publishes above a threshold (default `0.95`) after a cold-check by a second Sonnet
  call; below it → human queue with the draft ready. New `moderation_log` fields: `ai_whataboutism_suggested`,
  `ai_whataboutism_draft`, `ai_whataboutism_confidence`, `ai_whataboutism_latency_ms`. New `final_action` value
  `auto_whataboutism_replied`. Per-page toggle `whataboutism_enabled`; global setting
  `whataboutism_auto_publish_threshold`. Collision policy: if fact-check is also active it wins and
  whataboutism goes to human review without auto-publishing.
- **AI signal badges in approved and hidden comments** — dedicated chips ("🔍 Fact-check", "↩️ Whataboutism")
  independent of the final decision, to see WHAT the AI had flagged even after a human override.
- **Filter by AI signals** — query param `signal` on `/api/comments/approved`, `/api/comments/hidden`,
  `/api/bans/comments`; filter bar in both screens, combinable with `decided_by`.
- **Filter by AI category** — query param `category` (sanitised `JSON_CONTAINS`) and a dropdown fed by `CAT_LABELS`.
- **Per-category coloured chips** — thematic palette via the new `categoryChip(cat)` helper in `config.js`.
- **Sonnet sub-calls counter** — new `sonnet_subcalls` metric (fact_check + whataboutism) in `/api/stats`.
- **Pro wall counter** — the locked Reports screen shows how many reports have accumulated.
- **Hidden queue includes pending reportable** — `escalated_reportable` comments now also appear in Hidden
  comments, marked "⏳ awaiting legal review", distinct from the generic "⚠️ reportable".
- **Explicit decider badge** — when `human_user_id` is missing the chip shows "Hidden by AI" (it was empty).

### Changed
- **Pro/Free alignment** — `reportable_queue` and `advanced_stats` are now really gated by the license server
  (they were declared Pro on the landing page but free in the code). The UI hides them behind the Pro wall.
- **Stats split** — `/api/stats` always returns the basic counters; chart fields (`by_stage`, `by_ai_decision`,
  `sonnet_subcalls`, 30-day trends) only with the `advanced_stats` feature.
- **Unified landing pricing cards** — 3 cards (Free / Pro / Advanced) with the same anatomy.
- **Advanced pricing** — €29.90/month or €299/year (17% yearly discount); follows the billing toggle with Pro.
- **Slack/email + Instagram** moved from "Pro coming soon" to "Community coming soon" (basic utility, not
  editorial gating).
- **README + landing** — separate Pro/Free sections; "Newsroom" renamed Advanced everywhere; license feature
  keys are not published in public docs (they remain only in `LicenseService::KNOWN_FEATURES`).
- **URLs in replies** — removed the "no link" warnings from the fact-check and whataboutism prompts and modals:
  Facebook accepts links in Page replies.
- **Commit identity tracking** — local git config fixed to `checco83casali <checco83@gmail.com>`.

### Fixed
- **Broken stats `by_stage`** — the `(array)` cast on an Illuminate Collection exposed internal properties as JSON
  keys, hiding the real data. Replaced with `->all()` + `array_map('intval')`; same preventive fix on
  `by_ai_decision`.
- **Invisible prices on the landing** — the CSS selector `.plan-price span` also squashed the
  `<span id="price-display">` and `<span id="adv-price-display">` holding the whole price on Pro/Advanced.
  Narrowed to `.price-suffix`.

### Migration required
- **`002_whataboutism.sql`** — ALTER on `moderation_log` (+4 whataboutism columns), `page_settings` (+toggle),
  `app_settings` (+threshold). Run once on existing installations.

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
