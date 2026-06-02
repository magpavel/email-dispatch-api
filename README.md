# email-dispatch-api

A production-style Symfony 7 backend API for asynchronous email dispatch. Built as a portfolio demonstration of backend architecture, queue processing, provider abstraction, observability, and testing practices.

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

```bash
git clone https://github.com/yourname/email-dispatch-api.git
cd email-dispatch-api

cp .env.example .env.local   # edit as needed
docker compose up -d
```

Inside Docker the app runs on http://localhost:8000.  
RabbitMQ management UI: http://localhost:15673  
Mailhog UI: http://localhost:8026

### First run (database setup)

```bash
# On your host (using the exposed port 5433):
php bin/console doctrine:schema:create

# Or exec into the app container:
docker compose exec app php bin/console doctrine:schema:create
```

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
