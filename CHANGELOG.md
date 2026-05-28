# Changelog

All notable changes to this project will be documented in this file.  
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

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
  Setting globale `whataboutism_auto_publish_threshold`.
  Collision policy: se anche `fact_check_suggested` è attivo, il fact-check ha
  priorità editoriale e il whataboutism va a revisione umana senza auto-publish.

### Planned
- Multi-platform support (Instagram, LinkedIn)
- Slack/email notifications when queue exceeds threshold
- Bot & coordinated campaign detection
- Meta account metadata integration (account age, follower count)
- Scheduled report export (PDF/CSV)

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

[Unreleased]: https://github.com/checco83casali/social-moderation-hub/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/checco83casali/social-moderation-hub/releases/tag/v1.0.0
