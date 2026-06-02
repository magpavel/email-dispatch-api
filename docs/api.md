# API Reference

## POST /api/emails

Submit an email for asynchronous delivery.

**Request headers**

| Header | Required | Description |
|---|---|---|
| `Content-Type` | Yes | `application/json` |
| `Idempotency-Key` | No | Any string ≤255 chars. Same key returns the existing record without re-dispatching. |
| `X-Request-Id` | No | Caller-supplied correlation ID. Echoed in response and logs. |

**Request body**

```json
{
  "recipient_email": "user@example.com",
  "subject": "Welcome!",
  "html_body": "<h1>Hello</h1>",
  "text_body": "Hello",
  "metadata": {"campaign": "onboarding"},
  "preferred_provider": "mailgun"
}
```

| Field | Type | Required | Constraints |
|---|---|---|---|
| `recipient_email` | string | Yes | Valid email, max 320 chars |
| `subject` | string | Yes | 1–998 chars |
| `html_body` | string | Yes | Non-empty |
| `text_body` | string | No | Plain-text alternative |
| `metadata` | object | No | Arbitrary key-value pairs |
| `preferred_provider` | string | No | `log`, `smtp`, or `mailgun` |

**Response — 202 Accepted**

```json
{
  "id": "019500000000700080000000000000001",
  "status": "queued",
  "provider": null,
  "provider_message_id": null,
  "retry_count": 0,
  "last_error": null,
  "created_at": "2026-06-01T12:00:00+00:00",
  "updated_at": "2026-06-01T12:00:00+00:00",
  "processed_at": null
}
```

**Response — 422 Unprocessable Entity** (validation failure)

```json
{
  "errors": {
    "recipientEmail": "Recipient email is required.",
    "htmlBody": "HTML body is required."
  }
}
```

**Response — 400 Bad Request** (malformed JSON)

```json
{ "error": "Invalid JSON body." }
```

---

## GET /api/emails/{id}

Poll the current state of a previously submitted email.

**Path parameters**

| Parameter | Type | Description |
|---|---|---|
| `id` | UUID | The `id` returned by `POST /api/emails` |

**Response — 200 OK**

Same shape as the 202 response. `status` will be one of: `queued`, `processing`, `sent`, `delivered`, `failed`, `bounced`.

**Response — 404 Not Found**

```json
{ "error": "Email not found." }
```

---

## POST /api/webhooks/mailgun

Receive delivery events from Mailgun. Matches events to emails by `providerMessageId` and updates status.

**Authentication**

Pass the `MAILGUN_WEBHOOK_SECRET` value in the `X-Webhook-Secret` header (or `?secret=` query param). If the env var is empty, the check is skipped.

**Request body** (Mailgun format)

```json
{
  "event-data": {
    "event": "delivered",
    "message": {
      "headers": {
        "message-id": "<abc@sandbox.mailgun.org>"
      }
    }
  }
}
```

Supported `event` values:

| Event | Resulting status |
|---|---|
| `delivered` | `delivered` |
| `failed`, `rejected` | `failed` |
| `complained`, `bounced` | `bounced` |

**Response — 200 OK**

```json
{ "status": "ok" }
```

or (if no matching email found):

```json
{ "status": "ignored", "reason": "no matching email" }
```

**Response — 401 Unauthorized** (bad signature)

```json
{ "error": "Invalid signature." }
```
