# Deployment & Security Hardening

This guide describes how to deploy Social Moderation Hub securely. The core
principle: **only one endpoint needs to be reachable from the public internet —
the Meta webhook. Everything else (dashboard, API, login, page-connect) should
be restricted to internal/trusted IPs.**

> The **server firewall / reverse proxy is the authoritative control.** The
> application also ships an opt-in safety net (`AccessGuardMiddleware`) for
> defense in depth, but never rely on the app alone.

---

## 1. Public vs internal surface

| Path                         | Audience                | Exposure            |
|------------------------------|-------------------------|---------------------|
| `GET/POST /webhook/meta`     | Meta servers            | **PUBLIC**          |
| `GET /appeal?token=…`        | End-users (commenters)  | PUBLIC (optional)¹  |
| `GET /public/policy(.json)`  | Anyone (transparency)   | PUBLIC (optional)¹  |
| `GET /privacy`               | Anyone (transparency)   | PUBLIC (optional)¹  |
| `/dashboard.html`, `/assets` | Moderators              | **INTERNAL ONLY**   |
| `/api/*`                     | Moderators (JWT)        | **INTERNAL ONLY**   |
| `/auth/*`                    | Moderators (login)      | **INTERNAL ONLY**   |
| `/connect_page.php`          | Admins (FB page setup)  | **INTERNAL ONLY**²  |

¹ Appeals and transparency pages are public *by design*, but if your moderators
appeal-review flow does not need to be reachable from outside, you can keep them
internal too and only publish `/webhook/meta`.

² `connect_page.php` performs the Facebook OAuth page-connect. The OAuth redirect
returns to the browser of the admin who started it; keep it internal and run the
connect flow from inside the network.

---

## 2. Server firewall — block external, allow internal (REQUIRED)

Ban all inbound traffic except what is strictly needed. Example with **UFW**
(Debian/Ubuntu): allow SSH and the webserver only from your internal ranges, and
expose the webserver publicly **only** if the webhook lives on the same host
(see the split-domain option in §4 to avoid this).

```bash
# Default deny inbound
ufw default deny incoming
ufw default allow outgoing

# SSH + app only from internal LAN / VPN
ufw allow from 10.0.0.0/8      to any port 22
ufw allow from 192.168.0.0/16  to any port 443

# (Only if the public webhook is served from THIS host:)
# expose 443 publicly and let the reverse proxy filter paths (see §3)
ufw allow 443/tcp

ufw enable
```

With **nftables/iptables** the equivalent is an allowlist on ports 22/443 for
your internal CIDRs and a drop policy for everything else.

> If the server sits behind a cloud provider, also set the **security group /
> network ACL** to the same allowlist — the OS firewall and the cloud firewall
> should agree.

---

## 3. Reverse proxy — expose ONLY the webhook publicly

Even on a single public host, the reverse proxy should publish only the public
paths and return 404 for everything else from the internet. Example **nginx**:

```nginx
server {
    listen 443 ssl;
    server_name yourdomain.com;

    root /var/www/social-moderation-hub/public;
    index index.php;

    # ── Public surface: reachable from anywhere ──────────────────
    location = /webhook/meta        { try_files $uri /index.php$is_args$args; }
    location ^~ /appeal             { try_files $uri /index.php$is_args$args; }
    location ^~ /public/            { try_files $uri /index.php$is_args$args; }
    location = /privacy             { try_files $uri /index.php$is_args$args; }

    # ── Everything else: internal IPs only ───────────────────────
    location / {
        allow 10.0.0.0/8;
        allow 192.168.0.0/16;
        allow 127.0.0.1;
        deny  all;
        try_files $uri /index.php$is_args$args;
    }

    location ~ \.php$ {
        # same allow/deny as above unless it is the webhook
        fastcgi_pass unix:/run/php/php-fpm.sock;
        include fastcgi_params;
        fastcgi_param SCRIPT_FILENAME $document_root$fastcgi_script_name;
    }

    ssl_certificate     /etc/letsencrypt/live/yourdomain.com/fullchain.pem;
    ssl_certificate_key /etc/letsencrypt/live/yourdomain.com/privkey.pem;
}
```

**Apache** equivalent: use `<Location>` blocks with `Require ip 10.0.0.0/8`
for the internal paths and `Require all granted` only for `/webhook/meta` and
the other public paths.

---

## 4. Split-domain option — public webhook on its own subdomain

If you want the dashboard host fully closed and the webhook reachable from the
internet, run the webhook on a **separate public subdomain** (e.g.
`hooks.yourdomain.com`) pointing at the same app, while the main domain
(`mod.internal.yourdomain.com`) stays IP-restricted.

Set in `.env`:

```ini
APP_URL=https://mod.internal.yourdomain.com     # dashboard / API origin (CORS)
PUBLIC_DOMAIN=hooks.yourdomain.com              # serves ONLY public paths
INTERNAL_IP_ALLOWLIST=10.0.0.0/8,192.168.0.0/16,127.0.0.1
TRUSTED_PROXIES=127.0.0.1                        # your reverse proxy IP(s)
```

In Meta's webhook configuration, set the callback URL to
`https://hooks.yourdomain.com/webhook/meta`.

What the app then enforces (`AccessGuardMiddleware`, defense in depth):

- Requests to **`PUBLIC_DOMAIN`** may reach **only** the public paths; anything
  else returns `404` (the dashboard/API are invisible on that hostname).
- Requests to any other host hitting a **non-public** path must come from an IP
  in **`INTERNAL_IP_ALLOWLIST`**, otherwise `403`.
- `X-Forwarded-For` is trusted **only** when the connecting peer is in
  **`TRUSTED_PROXIES`** — otherwise the real `REMOTE_ADDR` is used, so the
  client IP cannot be spoofed.

> This middleware is **opt-in**: with both `INTERNAL_IP_ALLOWLIST` and
> `PUBLIC_DOMAIN` empty it does nothing, and a single-host install keeps
> working. It complements — never replaces — the firewall/reverse-proxy rules.

---

## 5. How the public webhook is validated (already enforced in code)

The webhook is the one path open to the world, so it is validated on both verbs:

- **`GET /webhook/meta`** (Meta verification handshake): the `hub.verify_token`
  is compared in **constant time** (`hash_equals`) against
  `META_WEBHOOK_VERIFY_TOKEN`. If no token is configured, it **fails closed**
  (403). Set a strong random value:

  ```ini
  META_WEBHOOK_VERIFY_TOKEN=<random 32+ char string>
  ```

- **`POST /webhook/meta`** (event delivery): every body is verified with an
  **HMAC-SHA256 signature** (`X-Hub-Signature-256`) keyed on `META_APP_SECRET`,
  compared in constant time. A missing signature or an unset app secret is
  rejected (fails closed). Forged or replayed-without-signature payloads are
  dropped before any DB write.

No diagnostic logging of tokens is performed (the old `webhook_verify.log` was
removed — it leaked token hashes and could fill the disk).

---

## 6. Secrets & configuration checklist

- [ ] `APP_SECRET` set to a unique random 64-char value (signs JWT, appeal
      tokens, pseudonyms). The app **fails closed** if it is empty — there is no
      fallback secret.
- [ ] `META_APP_SECRET` set (webhook signature key).
- [ ] `META_WEBHOOK_VERIFY_TOKEN` set to a strong random value.
- [ ] `APP_ENV=production` (disables verbose error output).
- [ ] `.env` is outside the web root or denied by the webserver, and is **never**
      committed (already in `.gitignore`).
- [ ] HTTPS enforced everywhere (HSTS recommended).
- [ ] Database user limited to the app schema; no remote root.
- [ ] OAuth client secrets and `ANTHROPIC_API_KEY` kept only in `.env`.
- [ ] File permissions: `logs/` writable by PHP, everything else read-only to
      the web user.
- [ ] **Delete `public/install.php`** after setup (the web installer). It writes
      `.env` and runs SQL, so it must not stay reachable. It self-locks via the
      `.installed` file, but deleting it is the safe default.

---

## 7. Application-level protections already in place

These are enforced in code and require no configuration:

- **SQL injection**: all queries use parameterised query-builder bindings; raw
  fragments contain only fixed column/table names, never user input.
- **Authentication**: API requires a valid JWT (`HS256`, explicit algorithm — no
  `alg=none`/algorithm-confusion). Tokens expire after `SESSION_LIFETIME`.
- **Authorization**: admin-only operations (user management, settings, policies,
  page connect/disconnect, license, legal reporting) check `role === 'admin'`
  server-side, not just in the UI.
- **CORS**: `Access-Control-Allow-Origin` is locked to `APP_URL` — never `*` —
  so a third-party site cannot drive authenticated calls from a victim browser.
- **Output encoding**: all dynamic values in the dashboard and public pages are
  HTML-escaped (`esc()` / `htmlspecialchars`).
- **Appeal tokens**: signed (HMAC-SHA256), expiring, and additionally
  cross-checked against the stored token in the DB.
- **Secret redaction**: Graph API errors have `access_token` values redacted
  before logging or display.

---

## 8. Operational recommendations

- Keep dependencies patched: `composer update` periodically and review
  `composer audit`.
- Rotate `APP_SECRET` and `META_*` secrets if a leak is suspected (note: rotating
  `APP_SECRET` invalidates outstanding sessions and appeal links).
- Monitor `logs/` and the `webhook_events` table for repeated signature failures
  (possible probing).
- Take regular encrypted DB backups; the moderation log is also your audit trail.

---

## 9. Environment variables — production reference

Full template with inline comments: **`.env.example`**. Summary below.

### Required

| Variable | Notes |
|---|---|
| `APP_URL` | Dashboard/API origin. Also used for the CORS allowed origin. |
| `APP_SECRET` | Random 64-char. Signs JWT sessions, appeal tokens, pseudonyms. **Must be unique** — never equal to `META_WEBHOOK_VERIFY_TOKEN` or `META_APP_SECRET`. App fails closed if empty. |
| `APP_ENV` | `production` (hides verbose errors). |
| `DB_HOST` / `DB_PORT` / `DB_DATABASE` / `DB_USERNAME` / `DB_PASSWORD` | Database connection. Use a least-privilege DB user. |
| `ANTHROPIC_API_KEY` | Claude API key (moderation engine). |
| `META_APP_ID` / `META_APP_SECRET` | Facebook app id + secret. `META_APP_SECRET` is the HMAC key for webhook POST signatures. |
| `META_WEBHOOK_VERIFY_TOKEN` | Random value you choose; must match the Meta webhook "Verify Token". Keep **distinct** from `APP_SECRET`. |
| At least one OAuth provider | `OAUTH_GOOGLE_*`, `OAUTH_META_*`, or `OAUTH_MICROSOFT_*` for moderator login. |

### Strongly recommended

| Variable | Notes |
|---|---|
| `OAUTH_ALLOWED_EMAIL_DOMAINS` | CSV of allowed login domains (e.g. `rtv.sm`). Without it, **any** OAuth email can sign in. |
| `INTERNAL_IP_ALLOWLIST` | Restrict non-public paths to internal IPs (defense in depth — see §4). |
| `APP_TIMEZONE` | e.g. `Europe/Rome`. |
| `SITE_NAME` | Public-facing name (appeal page). |

### Optional

| Variable | Default | Notes |
|---|---|---|
| `PUBLIC_DOMAIN` | — | Split-domain: serve only public paths on this host (§4). |
| `TRUSTED_PROXIES` | — | Proxy IPs allowed to set `X-Forwarded-For`. |
| `PUBLIC_PATHS` | built-in | Override the public path prefixes. |
| `META_FB_LOGIN_CONFIG_ID` | — | FB Login for Business config id. |
| `META_GRAPH_VERSION` | `v19.0` | Pin Graph API version. |
| `OAUTH_MICROSOFT_TENANT_ID` | `common` | Only if using Microsoft login. |
| `SESSION_LIFETIME` | `86400` | JWT lifetime (seconds). |
| `LICENSE_SERVER_URL` | — | Optional remote license server (empty = no remote checks). |
| `HAIKU_CONFIDENCE_THRESHOLD` | `0.80` | Below → escalate to Sonnet. |
| `SONNET_CONFIDENCE_THRESHOLD` | `0.70` | Below → escalate to human. |
| `RECIDIVISM_COMMENT_BAN_LIMIT` | `3` | Violations before user ban. |

> **Generate the two app-chosen secrets:**
> ```bash
> php -r "echo 'APP_SECRET='.bin2hex(random_bytes(32)).PHP_EOL;"
> php -r "echo 'META_WEBHOOK_VERIFY_TOKEN='.bin2hex(random_bytes(24)).PHP_EOL;"
> ```
