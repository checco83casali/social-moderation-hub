# Meta Webhook Setup Guide

This guide explains how to connect your Facebook page to Social Moderation Hub
so that new comments are automatically sent to the moderation pipeline.

---

## Prerequisites

- A Meta Developer account at [developers.facebook.com](https://developers.facebook.com)
- A Facebook App (type: **Business**)
- Your Social Moderation Hub instance running at a public HTTPS URL
- Admin access to the Facebook page you want to moderate

---

## Step 1 — Create or configure your Meta App

1. Go to [developers.facebook.com/apps](https://developers.facebook.com/apps)
2. Select your app (or create one: **Business** type)
3. In the left menu, go to **Settings → Basic**
4. Copy **App ID** and **App Secret** → add to your `.env`:

```
META_APP_ID=your_app_id
META_APP_SECRET=your_app_secret
```

---

## Step 2 — Add the Webhooks product

1. In your app dashboard, click **Add Product**
2. Find **Webhooks** → click **Set Up**
3. Select object type: **Page**
4. Click **Subscribe to this object**

Fill in:
- **Callback URL**: `https://yourdomain.com/webhook/meta`
- **Verify Token**: use the value of `APP_SECRET` from your `.env`

Subscribe to these fields:
- ✅ `feed`
- ✅ `comments`

5. Click **Verify and Save**

If verification succeeds, you'll see a green checkmark.

---

## Step 3 — Request Page permissions

In your app, go to **App Review → Permissions and Features** and request:

- `pages_show_list`
- `pages_manage_metadata`
- `pages_read_engagement`
- `pages_manage_posts` *(needed to delete comments)*

For development/testing, these work without review in **Development mode**
for users with a role in the app.

---

## Step 4 — Connect your page from the dashboard

1. Open `https://yourdomain.com/dashboard.html`
2. Navigate to **Pagine Facebook**
3. Click **Connetti pagina** and follow the Facebook Login flow
4. Select the page you want to moderate
5. The system will automatically subscribe the webhook for that page

---

## Step 5 — Test the webhook

Post a test comment on your Facebook page. Within seconds it should appear
in the **Coda revisione** section of the dashboard.

You can also send a test event from the Meta dashboard:
**Webhooks → Page → Test** → select `feed` → **Send Test**

---

## Troubleshooting

| Symptom | Check |
|---|---|
| Verification fails | `APP_SECRET` in `.env` matches the Verify Token you entered in Meta |
| Comments not arriving | Page webhook subscription active? Check `webhook_verified` column in `connected_pages` table |
| Comments arriving but not moderated | Is there an active policy? Check `policies` table: `is_active = 1` |
| Signature validation errors | `META_APP_SECRET` correct? Check `logs/app.log` |
| Comments deleted on Facebook but not in DB | Check `moderation_log` table for `ai_decision = remove` entries |

---

## Webhook security

Every incoming webhook POST is validated using HMAC-SHA256 signature verification
(`X-Hub-Signature-256` header). Requests with invalid signatures are rejected with HTTP 403.

Never expose your `META_APP_SECRET` or `APP_SECRET` publicly.
