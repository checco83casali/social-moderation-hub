# Contributing to Social Moderation Hub

Thank you for your interest in contributing.  
Please read these guidelines before opening issues or pull requests.

---

## Code of Conduct

Be respectful. Constructive criticism is welcome; personal attacks are not.

---

## Reporting Bugs

Use the **Bug Report** issue template. Include:
- PHP version (`php -v`)
- MySQL version
- Steps to reproduce
- Expected vs actual behaviour
- Relevant log output from `logs/app.log`

## Suggesting Features

Use the **Feature Request** issue template.  
Explain the use case, not just the feature.

---

## Pull Requests

### Before you start

- Open an issue first for significant changes — avoid duplicate work
- Check the `Unreleased` section in [CHANGELOG.md](CHANGELOG.md)

### Branch naming

| Type | Pattern | Example |
|------|---------|---------|
| Feature | `feat/short-description` | `feat/instagram-support` |
| Bug fix | `fix/short-description` | `fix/webhook-signature` |
| Docs | `docs/short-description` | `docs/api-reference` |
| Refactor | `refactor/short-description` | `refactor/ban-service` |

### Commit messages (Conventional Commits)

```
feat: add Instagram webhook support
fix: correct Meta token exchange error handling
docs: add API authentication section
refactor: extract comment parsing to dedicated method
```

### Checklist before submitting

- [ ] Code follows existing style (PSR-12 for PHP)
- [ ] No secrets or `.env` values committed
- [ ] `vendor/` not committed
- [ ] Tested manually against a real or mock Meta webhook
- [ ] CHANGELOG.md updated under `[Unreleased]`

---

## Development Setup

```bash
git clone https://github.com/checco83casali/social-moderation-hub.git
cd social-moderation-hub
composer install
cp .env.example .env
# Edit .env with your credentials
php install.php
php -S localhost:8080 -t public
```

Or with Docker:

```bash
docker-compose up -d
```

---

## Project Structure

```
src/
  Controllers/   # HTTP request handlers (thin layer)
  Services/      # Business logic (Claude, Meta, Ban, OAuth)
  Middleware/    # JWT auth guard
  Config/        # DI container definitions
database/
  migrations/    # Raw SQL schema files
public/          # Web root — index.php + dashboard.html
widget/          # Embeddable JS widget
docs/            # Documentation
```

**Rules:**
- Controllers stay thin — business logic belongs in Services
- Every Service is injected via DI container, never instantiated directly
- No `die()`, no raw `echo` outside controllers
- All DB access via Eloquent query builder (no raw SQL except migrations)
