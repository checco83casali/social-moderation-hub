# API Reference

Base URL: `https://yourdomain.com`

All `/api/*` endpoints require:
```
Authorization: Bearer <jwt_token>
Content-Type: application/json
```

JWT tokens are issued after OAuth login and expire after 24 hours (configurable via `SESSION_LIFETIME`).

---

## Authentication

### GET `/auth/{provider}`
Redirects to OAuth provider login page.

**Providers:** `google`, `meta`, `microsoft`

### GET `/auth/{provider}/callback`
Handles OAuth callback. On success, redirects to:
```
/dashboard.html?token=<jwt>
```

### GET `/api/me`
Returns current authenticated user.

**Response:**
```json
{
  "id": 1,
  "name": "Marco Rossi",
  "email": "marco@example.com",
  "role": "admin"
}
```

---

## Moderation Queue

### GET `/api/queue`

Returns comments awaiting human review.

**Query params:**
- `page` (int, default 1)
- `limit` (int, default 25, max 100)

**Response:**
```json
{
  "total": 12,
  "page": 1,
  "per_page": 25,
  "items": [
    {
      "id": 42,
      "content": "Comment text here",
      "received_at": "2025-01-15 14:32:00",
      "display_name": "Giovanni M.",
      "violation_count": 2,
      "ban_status": "clean",
      "page_name": "My Brand Page",
      "ai_stage": "sonnet",
      "ai_decision": "uncertain",
      "ai_confidence": 0.61,
      "ai_reason": "Ambiguous tone — could be frustrated customer or coordinated attack",
      "ai_categories": ["harassment"],
      "ai_severity": "medium"
    }
  ]
}
```

### POST `/api/comments/{id}/decide`

Apply a human moderation decision.

**Body:**
```json
{
  "decision": "allow",
  "note": "Legitimate complaint, no violation"
}
```

`decision`: `"allow"` or `"remove"`  
`note`: optional, stored in audit log

**Response:**
```json
{ "action": "approved_by_human", "comment_id": 42 }
```

---

## Statistics

### GET `/api/stats`

Dashboard summary for the last 30 days.

**Response:**
```json
{
  "queue_pending": 3,
  "total_comments_30d": 1989,
  "removed_30d": 142,
  "approved_30d": 1847,
  "active_bans": 12,
  "by_stage": { "haiku": 1654, "sonnet": 298, "human": 37 },
  "by_ai_decision": { "allow": 1847, "remove": 105, "uncertain": 37 }
}
```

### GET `/api/learning-data` *(admin only)*

Human ban decisions with AI context, for policy refinement.

**Response:**
```json
{
  "count": 87,
  "data": [
    {
      "ban_type": "perm_ban",
      "categories": ["scam", "spam"],
      "reason": "Repeated pig butchering script",
      "comment_text": "I made €5000 in one week...",
      "ai_decision": "uncertain",
      "ai_confidence": 0.58,
      "ai_stage": "sonnet",
      "created_at": "2025-01-10 09:15:00"
    }
  ]
}
```

---

## User Management

### GET `/api/users/{id}`

Social user detail with ban history and recent comments.

### POST `/api/users/{id}/ban` *(admin only)*

**Body:**
```json
{
  "reason": "Repeated scam promotion",
  "page_id": 1,
  "categories": ["scam"]
}
```

### DELETE `/api/users/{id}/ban` *(admin only)*

Lift active ban.

**Body:**
```json
{ "reason": "Ban lifted after appeal" }
```

---

## Policy Management

### GET `/api/policies`
List all policy versions.

### GET `/api/policies/active`
Return currently active policy.

### POST `/api/policies` *(admin only)*

Create new policy version.

**Body:**
```json
{
  "name": "Strict Holiday Policy",
  "description": "Tighter rules for the Christmas period",
  "system_prompt": "You are a content moderator..."
}
```

### POST `/api/policies/{id}/activate` *(admin only)*

Activate a policy (deactivates all others).

---

## Pages

### GET `/api/pages`
List connected Facebook pages.

### POST `/api/pages/available`

List pages manageable by a user token.

**Body:**
```json
{ "user_token": "<short_lived_fb_user_token>" }
```

**Response:**
```json
{
  "long_lived_token": "...",
  "pages": [
    { "id": "123456", "name": "My Brand", "already_connected": false }
  ]
}
```

### POST `/api/pages/connect`

Connect a page and subscribe webhook.

**Body:**
```json
{
  "page_id": "123456",
  "page_name": "My Brand",
  "page_access_token": "..."
}
```

### PUT `/api/pages/{id}/toggle`
Enable or pause moderation for a page.

### DELETE `/api/pages/{id}` *(admin only)*
Disconnect page (soft delete, data preserved).

---

## Error responses

All errors follow this format:

```json
{ "error": "Human-readable error message" }
```

| HTTP Status | Meaning |
|---|---|
| 400 | Bad request / malformed body |
| 401 | Missing or invalid JWT |
| 403 | Valid JWT but insufficient role |
| 404 | Resource not found |
| 409 | Conflict (e.g. page already connected) |
| 422 | Validation error |
| 502 | Upstream API error (Meta or Anthropic) |
