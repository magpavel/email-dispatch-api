# Email Dispatch API

![CI](https://github.com/magpavel/email-dispatch-api/actions/workflows/ci.yml/badge.svg)
![PHP](https://img.shields.io/badge/PHP-8.3%2B-blue)
![Symfony](https://img.shields.io/badge/Symfony-7-black)

A Symfony backend service for asynchronous email delivery with provider abstraction, queue processing, retries, webhooks, and delivery status tracking.

## Why this project exists

This project was built as a portfolio backend system to demonstrate asynchronous processing, provider abstraction, API design, testing, and production-oriented Symfony architecture.

## Implemented features

- [x] REST API for email submission
- [x] Async processing with Symfony Messenger
- [x] Provider abstraction and failover
- [x] Status tracking
- [x] Webhook endpoint
- [x] Unit and integration tests
- [x] Docker Compose local environment
- [x] GitHub Actions CI

## What it does

- Accepts email-send requests via a REST API
- Persists them to PostgreSQL and dispatches an async job to RabbitMQ
- A worker consumes jobs and sends emails through a configurable provider (log, SMTP, Mailgun)
- Supports failover: if the preferred provider fails, it falls back to the next configured provider
- Exposes status polling (`GET /api/emails/{id}`) and a Mailgun webhook endpoint for delivery updates
- Maintains a full status lifecycle: `queued → processing → sent → delivered` (or `failed / bounced`)

## Architecture overview

```
POST /api/emails
      │
      ▼
EmailController          (thin: parse, validate, delegate)
      │
      ▼
EmailSubmitService       (application logic: idempotency check, persist, dispatch)
      │
      ├─── Email entity ──► PostgreSQL (Doctrine ORM)
      │
      └─── SendEmailMessage ──► RabbitMQ (Symfony Messenger)
                                    │
                                    ▼
                              SendEmailHandler
                                    │
                              ProviderSelector   (ordered fallback list)
                                    │
                    ┌───────────────┼───────────────┐
                    ▼               ▼               ▼
             LogEmailProvider  SmtpEmailProvider  MailgunEmailProvider
```

**Key design decisions:**

- **Controllers are thin.** They parse JSON, run validation, and delegate to application services.
- **`EmailSubmitService`** owns submit logic (idempotency, persistence, dispatch). No business logic leaks into the controller.
- **`ProviderSelector`** is injected through `ProviderSelectorInterface`, making it replaceable and testable.
- **`SendEmailHandler`** drives the full send lifecycle: mark processing → try providers → mark sent/failed.
- **`Email` entity** enforces status transitions through explicit mutation methods, not raw setters.
- **Provider adapters** implement `EmailProviderInterface`. Adding a new provider means adding one class.

## Why Symfony Messenger

Messenger gives us:
- **Decoupled dispatch** — the HTTP request returns immediately after persisting; sending happens asynchronously.
- **Retry with back-off** — configurable retry strategy with exponential delay.
- **Dead-letter queue** — failed messages land in a `failed` transport for inspection without data loss.
- **Transport swap** — switching from RabbitMQ to Redis or SQS is a one-line change.

## Status lifecycle

```
queued ──► processing ──► sent ──► delivered
                  │
                  └──► failed ──► (processing, via Messenger retry)
                                       │
                            (after max retries: stays failed)

sent ──► bounced   (via webhook)
```

## Provider strategy

Providers are tried in order. The first success wins. The log provider is always the last fallback.

| Provider | Behaviour |
|---|---|
| `log` | Writes a structured log line, returns a fake message ID |
| `smtp` | Sends via Symfony Mailer using `MAILER_DSN` |
| `mailgun` | Calls the Mailgun HTTP API; set `MAILGUN_FAKE_MODE=true` for local/test use |

Set `DEFAULT_EMAIL_PROVIDER` in your env to choose the default. Callers can also pass `preferred_provider` per request.

---

## Local setup

**Requirements:** Docker, Docker Compose, PHP 8.3, Composer.

### 1. Clone and install dependencies

```bash
git clone https://github.com/magpavel/email-dispatch-api.git
cd email-dispatch-api
composer install
```

### 2. Start all services

```bash
docker compose up -d
```

This starts PostgreSQL (port 5433), RabbitMQ (port 5673), Mailhog (port 1026), the app (port 8000), and the async worker. Wait ~10 seconds for services to become healthy:

```bash
docker compose ps   # all should show "running" or "healthy"
```

| Service | URL |
|---|---|
| API | http://localhost:8000 |
| RabbitMQ management | http://localhost:15673 (guest/guest) |
| Mailpit (caught emails) | http://localhost:8026 |

### 3. Run database migrations

```bash
php bin/console doctrine:migrations:migrate --no-interaction
```

### 4. Verify the full flow

**Submit an email:**

```bash
curl -s -X POST http://localhost:8000/api/emails \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: test-001" \
  -d '{
    "recipient_email": "test@example.com",
    "subject": "Hello",
    "html_body": "<p>Hello world</p>"
  }'
```

Copy the `id` from the response. Status will be `queued`.

**Poll until sent (a few seconds):**

```bash
curl -s http://localhost:8000/api/emails/{id}
```

Status should change to `sent`, provider to `log`. Check worker logs to see the structured log entry:

```bash
docker compose logs worker
```

**Test idempotency** — send the same request again with the same `Idempotency-Key: test-001`. You should get back the identical `id`.

**Test a webhook delivery event** — use the `provider_message_id` from the status response:

```bash
curl -s -X POST http://localhost:8000/api/webhooks/mailgun \
  -H "Content-Type: application/json" \
  -d '{
    "event-data": {
      "event": "delivered",
      "message": {"headers": {"message-id": "PROVIDER_MESSAGE_ID_HERE"}}
    }
  }'
```

Poll status again — it should now show `delivered`.

**Test validation:**

```bash
curl -s -X POST http://localhost:8000/api/emails \
  -H "Content-Type: application/json" \
  -d '{"subject": "missing fields"}'
```

Expect 422 with field-level error messages.

### 5. Run the test suite

```bash
php bin/console doctrine:database:create --env=test --if-not-exists
php bin/phpunit --testdox
```

Integration tests automatically create and truncate the schema — no manual setup needed beyond the database existing.

---

## Running the worker

The `worker` service in `docker-compose.yml` starts automatically. To run manually:

```bash
php bin/console messenger:consume async --time-limit=3600 --memory-limit=128M -vv
```

---

## API examples

### Submit an email

```bash
curl -s -X POST http://localhost:8000/api/emails \
  -H "Content-Type: application/json" \
  -H "Idempotency-Key: my-unique-key-001" \
  -d '{
    "recipient_email": "user@example.com",
    "subject": "Welcome!",
    "html_body": "<h1>Hello</h1>",
    "text_body": "Hello",
    "metadata": {"campaign": "onboarding"},
    "preferred_provider": "mailgun"
  }'
```

**Response (202 Accepted):**

```json
{
  "id": "01950000-0000-7000-8000-000000000001",
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

### Poll status

```bash
curl http://localhost:8000/api/emails/01950000-0000-7000-8000-000000000001
```

**Response (200 OK, after worker runs):**

```json
{
  "id": "01950000-0000-7000-8000-000000000001",
  "status": "sent",
  "provider": "log",
  "provider_message_id": "log-xxxxxxxx-xxxx-xxxx-xxxx-xxxxxxxxxxxx",
  "retry_count": 0,
  "last_error": null,
  "created_at": "...",
  "updated_at": "...",
  "processed_at": "..."
}
```

### Validation error

```bash
curl -s -X POST http://localhost:8000/api/emails \
  -H "Content-Type: application/json" \
  -d '{"subject": "Test"}'
```

**Response (422 Unprocessable Entity):**

```json
{
  "errors": {
    "recipientEmail": "Recipient email is required.",
    "htmlBody": "HTML body is required."
  }
}
```

### Mailgun webhook (delivery event)

```bash
curl -s -X POST http://localhost:8000/api/webhooks/mailgun \
  -H "Content-Type: application/json" \
  -H "X-Webhook-Secret: your-secret" \
  -d '{
    "event-data": {
      "event": "delivered",
      "message": {"headers": {"message-id": "<your-provider-message-id>"}}
    }
  }'
```

---

## Running tests

```bash
# All tests
php bin/phpunit

# By suite
php bin/phpunit tests/Unit
php bin/phpunit tests/Handler
php bin/phpunit tests/Integration

# With test output
php bin/phpunit --testdox
```

Integration tests require PostgreSQL. They create and truncate the schema automatically.

See [docs/testing.md](docs/testing.md) for the full breakdown.

---

## Code quality

```bash
# PHPStan (level 8)
vendor/bin/phpstan analyse --configuration=phpstan.dist.neon --memory-limit=512M

# Coding standards check
vendor/bin/php-cs-fixer fix --dry-run --diff

# Fix in place
vendor/bin/php-cs-fixer fix

# Run everything CI runs
make ci
```

---

## Make targets

```
make up              Start Docker services
make down            Stop services
make test            Full test suite
make test-unit       Unit tests only
make test-integration  Integration tests only
make stan            PHPStan
make cs              CS check (dry-run)
make cs-fix          CS auto-fix
make migrate         Run migrations
make shell           Shell into app container
make logs            Tail Docker logs
```

---

## Design tradeoffs

**UUIDs v7 as IDs** — time-ordered, sortable by `created_at` without a separate column. Good for Postgres index performance.

**`Email` entity mutations instead of setters** — `markAsSent()`, `markAsFailed()` etc. make valid state transitions explicit and keep status-change logic colocated with the entity.

**No Symfony Workflow component** — the state machine is simple enough that a `canTransitionTo()` method on the enum covers it without adding a dependency or config file. Would revisit if transitions needed guards, callbacks, or auditing.

**Single `failed` Messenger transport** — Messenger's dead-letter queue is the retry/inspection mechanism. No custom retry table needed; the transport itself holds failures.

**Webhook signature check is a shared secret** — Mailgun supports HMAC signature verification. This implementation uses a simpler shared-secret header check to keep the webhook code readable as a demo. A production deployment should switch to HMAC.

**`MAILGUN_FAKE_MODE=true`** — allows running and testing the Mailgun code path without a real API key or outbound HTTP call. Useful in local dev and CI.
