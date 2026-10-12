# Go-Live Checklist

🇬🇧 English · [🇮🇹 Italiano](go-live-checklist.it.md)

Checklist to run through before putting Social Moderation Hub into production.

---

## 1. Server configuration

- [ ] PHP 8.1+ installed with extensions: `pdo_mysql`, `mbstring`, `openssl`, `curl`
- [ ] Document root points to `public/` (not to the project root)
- [ ] HTTPS enabled with a valid certificate
- [ ] The `.env` file is not reachable over the web (it must live outside `public/`)
- [ ] `.env` permissions are `600` (only the PHP process can read it)
- [ ] The `logs/` folder exists and is writable by the PHP process

---

## 2. `.env` configuration

- [ ] `APP_URL` set to the correct public URL (e.g. `https://moderation.sanmarinortv.sm`)
- [ ] `APP_SECRET` set to a random 64-character string (`php -r "echo bin2hex(random_bytes(32));"`)
- [ ] `APP_ENV=production`
- [ ] `DB_HOST`, `DB_DATABASE`, `DB_USERNAME`, `DB_PASSWORD` are correct
- [ ] `ANTHROPIC_API_KEY` set (or configured from the dashboard)
- [ ] `META_APP_ID`, `META_APP_SECRET` set
- [ ] `META_WEBHOOK_VERIFY_TOKEN` set (different from `APP_SECRET`)
- [ ] `OAUTH_ALLOWED_EMAIL_DOMAINS` set (e.g. `sanmarinortv.sm`) — **strongly recommended**
- [ ] At least one OAuth provider configured (Google, Meta or Microsoft) — or a local user created

---

## 3. Database and installer

- [ ] `php install.php` completed without errors
- [ ] The `policies` table has at least one row with `is_active = 1`
- [ ] At least one admin user created (first OAuth login or direct insert)

---

## 4. Meta integration

- [ ] Meta app created at [developers.facebook.com](https://developers.facebook.com) (type: Business)
- [ ] `META_APP_ID` and `META_APP_SECRET` in `.env` match the Meta app
- [ ] **Webhooks** product added and webhook verified (URL + token)
- [ ] **Facebook Login** product added with the correct callback URIs
- [ ] Facebook page connected from the dashboard (Pages → Connect page)
- [ ] `webhook_verified = 1` in `connected_pages` for the connected page
- [ ] Page token has `pages_read_engagement` (needed to read the post for the post-context feature)
- [ ] Meta app in **Live mode** (not Development) for real users

---

## 5. Network security

- [ ] Only `/webhook/meta` is reachable from the public internet
- [ ] Dashboard (`/`), API (`/api/`) and login (`/auth/`) reachable only from internal IPs or VPN
- [ ] (Optional) `INTERNAL_IP_ALLOWLIST` and/or `PUBLIC_DOMAIN` configured in `.env`
- [ ] (Optional) `TRUSTED_PROXIES` configured if there is a reverse proxy in front
- [ ] Full guide: [deployment-security.md](deployment-security.md)

---

## 6. Functional test

- [ ] Dashboard login works
- [ ] The Settings section opens and shows the correct values
- [ ] Post a test comment on the Facebook page → it appears in the queue within 30 seconds
- [ ] AI moderation processes the comment (check the moderation log)
- [ ] The AI decision is applied correctly on Facebook (hide/approve)
- [ ] Dev mode is **off** (`dev_mode = 0` in `app_settings`) — it is fine to start in dev mode to collect data, but switch it off to go live

---

## 7. (Optional) Auto-deploy via GitHub

- [ ] `GITHUB_WEBHOOK_SECRET` set in `.env`
- [ ] Webhook configured in GitHub → Settings → Webhooks
- [ ] `GITHUB_WEBHOOK_BRANCH=main`

---

## 8. (Optional) Cron jobs

- [ ] GDPR data retention: `data_retention_days` set in Settings and a nightly cron: `php /path/bin/retention-purge.php`
- [ ] Edited-comment re-analysis (Pro): a cron **every minute**: `* * * * * php /path/bin/recheck-edits.php`
- [ ] Licensed features (post context, AI training, edited-comment re-analysis, prompt effectiveness) are enabled on the license server

---

> Once the checklist is done, remove or block access to `install.php`
> if you do not plan to reinstall.
