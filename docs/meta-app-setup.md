# Meta App — Complete Setup Guide

🇬🇧 English · [🇮🇹 Italiano](meta-app-setup.it.md)

This guide covers creating and configuring the Meta app needed to connect
Social Moderation Hub to Facebook pages.

---

## Prerequisites

- Meta Developer account (free) → [developers.facebook.com](https://developers.facebook.com)
- Admin access to the Facebook page you want to moderate
- Social Moderation Hub installed on a public HTTPS URL

---

## Step 1 — Create the app on Meta for Developers

1. Go to [developers.facebook.com/apps](https://developers.facebook.com/apps)
2. Click **Create app**
3. Choose the type: **Business** (required for the Pages APIs)
4. Enter:
   - **App name**: e.g. `Social Moderation Hub` (not shown to users)
   - **Contact email**: your email
   - **Business account**: link your Business Manager if you have one
5. Click **Create app**

---

## Step 2 — Get the App ID and App Secret

1. In the app dashboard, go to **Settings → Basic**
2. Copy:
   - **App ID** → `META_APP_ID` in `.env`
   - **App Secret** → `META_APP_SECRET` in `.env`

```env
META_APP_ID=123456789012345
META_APP_SECRET=abcdef1234567890abcdef1234567890
```

> ⚠️ Never expose the app secret publicly. Keep it only in the `.env` file on the server.

---

## Step 3 — Add the Facebook Login product

Facebook Login is required to connect pages from the dashboard.

1. In the left sidebar, click **Add a Product**
2. Find **Facebook Login** → click **Set up**
3. Choose **Web**
4. Under **Valid OAuth Redirect URIs** add:
   ```
   https://moderation.yourdomain.com/auth/meta/callback
   ```
5. Save the changes

### Permissions required for Facebook Login

In **Facebook Login → Settings**, make sure these are enabled:

| Permission | Why |
|---|---|
| `pages_show_list` | List the pages the user manages |
| `pages_manage_metadata` | Read page metadata and manage the webhook |
| `pages_read_engagement` | Read comments (and the post text, for the post-context feature) |
| `pages_manage_engagement` | Hide/delete comments |
| `pages_read_user_content` | Read users' content on the page |

> In **development mode** these permissions work immediately for users who have a role in the app.
> For production (app in **live mode**) Meta's App Review is required.

---

## Step 4 — Add the Webhooks product

The webhook receives new comments from the Facebook page in real time.

1. Click **Add a Product** → find **Webhooks** → **Set up**
2. Select the object: **Page**
3. Click **Subscribe to this object**

Fill in the fields:

- **Callback URL**: `https://moderation.yourdomain.com/webhook/meta`
- **Verify token**: the value of `META_WEBHOOK_VERIFY_TOKEN` from your `.env`

```env
META_WEBHOOK_VERIFY_TOKEN=the_random_value_you_chose
```

> The verify token is a value **you choose** and put both in `.env` and in the
> Meta panel — it is not the App Secret. Keep them distinct.

4. Subscribe to the fields:
   - ✅ `feed`
   - ✅ `comments`

5. Click **Verify and save** — a green tick appears when the check succeeds.

---

## Step 5 — (Optional) Facebook Login for Business

If you want to use the simplified "Add pages" flow from the dashboard:

1. Go to **Products → Facebook Login for Business**
2. Create a **Saved configuration** with the permissions listed in Step 3
3. Copy the **configuration ID** → `.env`:

```env
META_FB_LOGIN_CONFIG_ID=123456789
```

---

## Step 6 — Connect the page from the dashboard

1. Open `https://moderation.yourdomain.com/dashboard.html`
2. Go to **Facebook Pages → + Connect page**
3. Follow the Facebook Login flow
4. Select the page to moderate
5. The webhook is subscribed automatically

---

## Step 7 — Check that it works

Post a test comment on the Facebook page. Within a few seconds it
should appear in the dashboard's **Review queue**.

You can also send a test event from the Meta dashboard:
**Webhooks → Page → Test** → select `feed` → **Send test**

---

## Step 8 — Go Live (production)

In development mode only users with a role in the app can use the permissions.
To go to production:

1. Go to **App Review → Permissions and features**
2. Request review for the permissions listed above
3. Once approved, switch **Development mode → Live**

> For pages you own and administer, App Review is not needed —
> Live mode is enough to get started.

---

## Troubleshooting

| Problem | Solution |
|---|---|
| Webhook verification fails | Check that `META_WEBHOOK_VERIFY_TOKEN` in `.env` matches exactly what you entered in Meta |
| Comments do not arrive | Check that the page has the webhook active: column `webhook_verified = 1` in `connected_pages` |
| Comments arrive but are not moderated | Is there an active policy? Check the `policies` table: `is_active = 1` |
| Signature errors (`403`) | Is `META_APP_SECRET` correct? Check `logs/app.log` |
| `pages_manage_engagement` denied | The app is in development mode and the user has no role in the app |
| Post context stays empty | The page token lacks `pages_read_engagement`: reconnect the page after granting it |

---

## Webhook security

Every incoming POST is validated with an HMAC-SHA256 signature (`X-Hub-Signature-256`),
compared in constant time. Requests without a signature, or with a wrong one, are
rejected with HTTP 403 before any processing.

> `/webhook/meta` is the only endpoint that must be reachable from the public
> internet. Everything else (dashboard, `/api`, login, page connection) should be
> restricted to internal IPs via firewall. See [deployment-security.md](deployment-security.md).
