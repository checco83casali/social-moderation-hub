<p align="center">
  <img src="public/favicon.svg" width="84" height="84" alt="Social Moderation Hub logo">
</p>

<h1 align="center">Social Moderation Hub</h1>

<p align="center">
  <strong>Two-stage AI + human content moderation for Facebook pages.</strong><br>
  Self-hosted · PHP 8.1+ · MySQL 8 · Claude AI · Meta Graph API · MIT License
</p>

---

## What it does

Every comment posted on your Facebook page is evaluated by Claude AI in a two-stage
escalation pipeline, with a human review queue as the final safety net:

```
Facebook comment received
        │
        ▼
  Claude Haiku   ──── confident decision ────▶  Allow / Hide / Remove
        │
   uncertain or low confidence
        │
        ▼
  Claude Sonnet  ──── confident decision ────▶  Allow / Hide / Remove
        │
   still uncertain
        │
        ▼
  Human review queue ──▶ Moderator decides ──▶  Allow / Hide / Remove
```

**Recidivism tracking** — repeated violations escalate automatically: comment
removal → temporary ban → permanent ban.

**Human decisions feed back** to the system as structured learning data, useful
for policy refinement.

**GDPR-friendly by default** — hide-with-appeal is the preferred action over
deletion; sensitive fields are anonymised on a configurable schedule.

---

## Features

- **Two-stage AI pipeline** — Claude Haiku (fast/cheap) → Sonnet (deeper) → Human
- **Context-aware moderation** — account age, follower count, internal violation history sent to Claude as context
- **Advanced threat detection** — scam patterns (pig butchering, fake giveaways, wallet theft), grooming signals, coordinated spam
- **Versioned policy management** — edit system prompts from the UI; full version history preserved
- **Human review queue** — prioritised dashboard with AI reasoning per comment
- **Appeals workflow** — hidden comments can be contested via a signed-URL form; admins decide
- **Progressive ban system** — comment removal → 1-day → 7-day → 30-day → permanent
- **Configurable data retention** — nightly job anonymises PII after N days (statistical fields preserved)
- **Multi-page Facebook integration** — connect several pages in one click via Facebook Login
- **Full audit trail** — every AI and human decision logged with model, confidence, latency
- **OAuth2 login** — Google, Meta, Microsoft (first user auto-becomes admin)
- **Pluggable Pro features** — optional license server
- **Self-hosted & private** — your data never leaves your server
- **MIT License** — use, fork, deploy freely

---

## Requirements

| Requirement      | Version                                                       |
|------------------|---------------------------------------------------------------|
| PHP              | ≥ 8.1                                                         |
| MySQL            | ≥ 8.0                                                         |
| Composer         | ≥ 2.0                                                         |
| Web server       | Apache (mod_rewrite) or Nginx                                 |
| Anthropic API    | [console.anthropic.com](https://console.anthropic.com)        |
| Meta App         | [developers.facebook.com](https://developers.facebook.com)    |

---

## Quick start

```bash
# 1. Clone
git clone https://github.com/checco83casali/social-moderation-hub.git
cd social-moderation-hub

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Configure
cp .env.example .env
# Edit .env: DB credentials, ANTHROPIC_API_KEY, META_APP_ID/SECRET, OAuth providers

# 4. Run the installer (creates DB, runs migrations, validates config)
#    CLI:  php install.php
#    or WEB: point the document root to public/ and open /install.php in a browser
php install.php

# 5. Point the web server document root to public/

# 6. Harden the deployment — REQUIRED for production
#    Restrict everything except the Meta webhook to internal IPs.
#    Full guide: docs/deployment-security.md
```

> ⚠️ **Before going to production, read [docs/deployment-security.md](docs/deployment-security.md).**
> Only the Meta webhook (`/webhook/meta`) needs to be reachable from the public
> internet — the dashboard, API, login and page-connect must be restricted to
> internal/trusted IPs via the server firewall (and optionally the built-in
> `INTERNAL_IP_ALLOWLIST` / `PUBLIC_DOMAIN` safety net).

### Docker (local dev)

```bash
cp .env.example .env
docker-compose up -d

# With phpMyAdmin:
docker-compose --profile dev up -d
```

App: `http://localhost:8080` · phpMyAdmin: `http://localhost:8081`

---

## First login

1. Open `https://yourdomain.com/auth/google` (or `/auth/meta`, `/auth/microsoft`)
2. The first account that logs in **automatically becomes admin**
3. Go to **Pagine Facebook** → **+ Aggiungi pagine** → Facebook Login → pick the pages you want to moderate
4. Webhook subscription happens automatically on connection
5. Comments will start flowing into the queue

---

## Data retention (GDPR)

The dashboard exposes an *Anonymizza dopo (giorni)* setting under **Impostazioni
→ Retention dati**. After N days every comment / social user / log / webhook
payload has its PII fields anonymised; statistical columns (AI decision,
severity, timestamps, ban counts) are preserved.

The work is done by a nightly cron entry:

```cron
0 3 * * * /usr/bin/php /path/to/social-moderation-hub/bin/retention-purge.php \
    >> /path/to/social-moderation-hub/logs/retention.log 2>&1
```

The Settings panel shows the last cron execution and warns if it's stale.

To wipe operational data and restart from scratch (configuration preserved):

```bash
mysql -u <user> -p <db> < database/scripts/reset-operational-data.sql
```

---

## Project structure

```
social-moderation-hub/
├── bin/
│   └── retention-purge.php       # Nightly anonymisation (cron)
├── public/
│   ├── index.php                 # Slim 4 entry point + all routes
│   ├── dashboard.html            # Admin SPA (no framework, dark theme)
│   ├── connect_page.php          # Standalone FB pages connect (legacy fallback)
│   ├── privacy.php               # Privacy policy template (editable from dashboard)
│   ├── appeal/                   # Public appeal form (signed URL)
│   └── assets/                   # CSS + JS
├── src/
│   ├── Config/
│   │   └── container.php         # DI container (PHP-DI)
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── LicenseController.php
│   │   ├── ModerationController.php
│   │   ├── PagesController.php
│   │   └── WebhookController.php
│   ├── Middleware/
│   │   └── AuthMiddleware.php    # JWT guard
│   └── Services/
│       ├── ClaudeService.php     # Haiku → Sonnet pipeline
│       ├── MetaGraphService.php  # Facebook Graph API
│       ├── ModerationService.php # Orchestration + context enrichment
│       ├── BanService.php        # Recidivism & ban lifecycle
│       ├── OAuthService.php      # Multi-provider OAuth2 + JWT
│       ├── LicenseService.php    # Pro feature gating
│       ├── RetentionService.php  # GDPR anonymisation
│       └── PolicyController.php  # Policy versioning
├── database/
│   ├── migrations/
│   │   └── 001_initial_schema.sql
│   └── scripts/
│       └── reset-operational-data.sql
├── widget/
│   └── connect.js                # Embeddable page-connection widget
├── docs/
├── docker-compose.yml
├── Dockerfile
├── install.php                   # Interactive CLI installer
└── .env.example
```

---

## API reference

All `/api/*` routes require an `Authorization: Bearer <jwt>` header.

| Method | Path                                | Description                                       |
|--------|-------------------------------------|---------------------------------------------------|
| GET    | `/auth/{provider}`                  | OAuth redirect (google / meta / microsoft)        |
| GET    | `/auth/{provider}/callback`         | OAuth callback                                    |
| GET    | `/api/me`                           | Current user info                                 |
| GET    | `/api/queue`                        | Human review queue (paginated)                    |
| POST   | `/api/comments/{id}/decide`         | Human decision `{decision, note}`                 |
| GET    | `/api/stats`                        | Dashboard stats (last 30 days)                    |
| GET    | `/api/users/{id}`                   | Social user + ban history                         |
| POST   | `/api/users/{id}/ban`               | Manual user ban                                   |
| DELETE | `/api/users/{id}/ban`               | Lift a ban                                        |
| GET    | `/api/policies`                     | List policy versions                              |
| GET    | `/api/policies/active`              | Active policy                                     |
| POST   | `/api/policies`                     | Create new policy version                         |
| POST   | `/api/policies/{id}/activate`       | Activate a policy                                 |
| GET    | `/api/pages`                        | Connected Facebook pages                          |
| GET    | `/api/pages/login-config`           | Facebook SDK config for the dashboard modal       |
| POST   | `/api/pages/available`              | List manageable pages from a FB user token        |
| POST   | `/api/pages/connect`                | Connect a single page (legacy)                    |
| POST   | `/api/pages/connect-batch`          | Connect multiple pages in one call                |
| POST   | `/api/pages/{id}/webhook/retry`     | Re-subscribe webhook for a page                   |
| PUT    | `/api/pages/{id}/toggle`            | Enable / pause moderation                         |
| DELETE | `/api/pages/{id}`                   | Disconnect a page                                 |
| GET    | `/api/retention/status`             | Retention window + last cron run (admin)          |
| GET    | `/api/license`                      | License status (admin)                            |
| GET    | `/webhook/meta`                     | Meta webhook verification                         |
| POST   | `/webhook/meta`                     | Meta webhook event receiver                       |

---

## Configuration reference

Key `.env` variables:

| Variable                       | Default        | Description                                                  |
|--------------------------------|----------------|--------------------------------------------------------------|
| `APP_URL`                      | *(required)*   | Public URL of your installation                              |
| `HAIKU_CONFIDENCE_THRESHOLD`   | `0.80`         | Below this → escalate to Sonnet                              |
| `SONNET_CONFIDENCE_THRESHOLD`  | `0.70`         | Below this → escalate to human                               |
| `RECIDIVISM_COMMENT_BAN_LIMIT` | `3`            | Violations before user ban                                   |
| `OAUTH_ALLOWED_EMAIL_DOMAINS`  | *(empty)*      | Restrict logins by domain (e.g. `mycompany.com`)             |
| `META_FB_LOGIN_CONFIG_ID`      | *(empty)*      | Facebook Login for Business saved config id (optional)       |
| `LICENSE_SERVER_URL`           | *(empty)*      | Remote license server (empty = no remote checks)             |
| `APP_ENV`                      | `production`   | Set to `development` for verbose errors                      |

---

## Roadmap

See [CHANGELOG.md](CHANGELOG.md) for the full feature list.

Planned next:
- Slack / email alerts when the human queue exceeds a threshold
- Instagram support (via Meta Graph API)
- Bot & coordinated campaign detection (cross-user behavioural analysis)
- Scheduled CSV/PDF report export
- Policy test mode — evaluate a sample comment before activating a new version

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

## License

[MIT](LICENSE) — free to use, modify, and distribute.
