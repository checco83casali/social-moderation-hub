# Social Moderation Hub

**Hybrid AI + Human content moderation for Facebook pages.**  
Self-hosted · PHP 8.1+ · MySQL 8 · Claude AI · Meta Graph API · MIT License

---

## What it does

Comments posted on your Facebook page are automatically evaluated by Claude AI through a three-stage escalation pipeline:

```
Facebook comment received
        │
        ▼
  Claude Haiku  ──── confident decision ────▶  Allow / Remove
        │
   uncertain or low confidence
        │
        ▼
  Claude Sonnet ──── confident decision ────▶  Allow / Remove
        │
   still uncertain
        │
        ▼
  Human review queue ──▶ Moderator decides ──▶  Allow / Remove + Learning data
```

**Recidivism tracking:** repeated violations escalate automatically from comment removal → temporary ban → permanent ban.

**Human decisions feed back** into the system as structured learning data, available for policy refinement.

---

## Features

- **Three-stage AI pipeline** — Claude Haiku (fast/cheap) → Sonnet (deeper) → Human
- **Context-aware moderation** — account age, follower count, internal violation history sent to Claude as context
- **Advanced threat detection** — scam patterns (pig butchering, fake giveaways, wallet theft), grooming signals, coordinated spam
- **Versioned policy management** — edit system prompts from the UI; full version history preserved
- **Human review queue** — prioritised dashboard with AI reasoning per comment
- **Progressive ban system** — comment → 1-day → 7-day → 30-day → permanent
- **Full audit trail** — every AI and human decision logged with model, confidence, latency
- **OAuth2 login** — Google, Meta, Microsoft (first user auto-becomes admin)
- **Embeddable widget** — connect Facebook pages without leaving your admin panel
- **Self-hosted & private** — your data never leaves your server
- **MIT License** — use, fork, deploy freely

---

## Requirements

| Requirement | Version |
|---|---|
| PHP | ≥ 8.1 |
| MySQL | ≥ 8.0 |
| Composer | ≥ 2.0 |
| Web server | Apache (with mod_rewrite) or Nginx |
| Anthropic API key | [console.anthropic.com](https://console.anthropic.com) |
| Meta App | [developers.facebook.com](https://developers.facebook.com) |

---

## Quick start

### Option A — Traditional server

```bash
# 1. Clone
git clone https://github.com/YOURUSERNAME/social-moderation-hub.git
cd social-moderation-hub

# 2. Install dependencies
composer install --no-dev --optimize-autoloader

# 3. Configure
cp .env.example .env
# Edit .env with your API keys and DB credentials

# 4. Run installer (creates DB, runs migrations, checks config)
php install.php

# 5. Point web server document root → public/
```

### Option B — Docker (recommended for local dev)

```bash
cp .env.example .env
# Edit .env

docker-compose up -d

# With phpMyAdmin for DB inspection:
docker-compose --profile dev up -d
```

App: http://localhost:8080  
phpMyAdmin: http://localhost:8081

---

## First login

1. Visit `https://yourdomain.com/auth/google` (or `/auth/meta`, `/auth/microsoft`)
2. The first account that logs in **automatically becomes admin**
3. Go to **Pagine Facebook** → connect your page
4. Set up the Meta webhook (see [docs/webhook-setup.md](docs/webhook-setup.md))
5. Comments will start flowing into the moderation pipeline

---

## Project structure

```
social-moderation-hub/
├── public/
│   ├── index.php            # Slim 4 entry point + all routes
│   ├── dashboard.html       # Admin SPA (no framework, dark theme)
│   └── .htaccess
├── src/
│   ├── Config/
│   │   └── container.php    # DI container (PHP-DI)
│   ├── Controllers/
│   │   ├── AuthController.php
│   │   ├── ModerationController.php
│   │   ├── PagesController.php
│   │   ├── PolicyController.php
│   │   └── WebhookController.php
│   ├── Middleware/
│   │   └── AuthMiddleware.php   # JWT guard
│   └── Services/
│       ├── ClaudeService.php    # Haiku → Sonnet → Human pipeline
│       ├── MetaGraphService.php # Facebook Graph API
│       ├── ModerationService.php# Orchestration + context enrichment
│       ├── BanService.php       # Recidivism & ban lifecycle
│       └── OAuthService.php     # Multi-provider OAuth2 + JWT
├── database/
│   └── migrations/
│       └── 001_initial_schema.sql
├── widget/
│   └── connect.js           # Embeddable page-connection widget
├── docs/
│   ├── api-reference.md
│   └── webhook-setup.md
├── .github/
│   ├── ISSUE_TEMPLATE/
│   └── PULL_REQUEST_TEMPLATE.md
├── docker-compose.yml
├── Dockerfile
├── install.php              # Interactive CLI installer
├── CHANGELOG.md
├── CONTRIBUTING.md
└── .env.example
```

---

## API reference

All `/api/*` routes require `Authorization: Bearer <jwt>` header.

| Method | Path | Description |
|---|---|---|
| GET | `/auth/{provider}` | OAuth redirect (google / meta / microsoft) |
| GET | `/auth/{provider}/callback` | OAuth callback |
| GET | `/api/me` | Current user info |
| GET | `/api/queue` | Human review queue (paginated) |
| POST | `/api/comments/{id}/decide` | Human decision `{decision, note}` |
| GET | `/api/stats` | Dashboard stats (last 30 days) |
| GET | `/api/learning-data` | Human ban decisions for policy review (admin) |
| GET | `/api/users/{id}` | Social user + ban history |
| POST | `/api/users/{id}/ban` | Manual user ban |
| DELETE | `/api/users/{id}/ban` | Lift a ban |
| GET | `/api/policies` | List all policy versions |
| GET | `/api/policies/active` | Active policy |
| POST | `/api/policies` | Create new policy version |
| POST | `/api/policies/{id}/activate` | Activate a policy |
| GET | `/api/pages` | Connected Facebook pages |
| POST | `/api/pages/available` | List manageable pages from FB token |
| POST | `/api/pages/connect` | Connect a page + subscribe webhook |
| PUT | `/api/pages/{id}/toggle` | Enable / pause moderation |
| DELETE | `/api/pages/{id}` | Disconnect a page |
| GET | `/webhook/meta` | Meta webhook verification |
| POST | `/webhook/meta` | Meta webhook event receiver |

---

## Configuration reference

Key `.env` variables:

| Variable | Default | Description |
|---|---|---|
| `HAIKU_CONFIDENCE_THRESHOLD` | `0.80` | Below this → escalate to Sonnet |
| `SONNET_CONFIDENCE_THRESHOLD` | `0.70` | Below this → escalate to human |
| `RECIDIVISM_COMMENT_BAN_LIMIT` | `3` | Violations before user ban |
| `OAUTH_ALLOWED_EMAIL_DOMAINS` | *(empty)* | Restrict logins by domain (e.g. `mycompany.com`) |
| `APP_ENV` | `production` | Set to `development` for verbose errors |

---

## Roadmap

See [CHANGELOG.md](CHANGELOG.md) for the full planned features list.

Priority next steps:
- [ ] Slack / email alerts when queue exceeds threshold
- [ ] Instagram support (via Meta Graph API)
- [ ] Bot & coordinated campaign detection (cross-user behavioural analysis)
- [ ] Scheduled CSV/PDF report export
- [ ] Policy test mode — evaluate a sample comment before activating

---

## Contributing

See [CONTRIBUTING.md](CONTRIBUTING.md).

---

## License

[MIT](LICENSE) — free to use, modify and distribute.
