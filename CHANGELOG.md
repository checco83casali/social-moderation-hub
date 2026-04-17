# Changelog

All notable changes to this project will be documented in this file.  
Format based on [Keep a Changelog](https://keepachangelog.com/en/1.0.0/).  
This project adheres to [Semantic Versioning](https://semver.org/).

---

## [Unreleased]

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

[Unreleased]: https://github.com/YOURUSERNAME/social-moderation-hub/compare/v1.0.0...HEAD
[1.0.0]: https://github.com/YOURUSERNAME/social-moderation-hub/releases/tag/v1.0.0
