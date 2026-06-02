# Testing

## Test suites

| Suite | Location | DB needed | What it covers |
|---|---|---|---|
| Unit | `tests/Unit/` | No | Enum logic, entity mutations, provider selector, ProviderResult |
| Handler | `tests/Handler/` | No | `SendEmailHandler` with mocked dependencies |
| Integration | `tests/Integration/` | Yes (PostgreSQL) | HTTP endpoints, full request/response cycle, webhook behavior |

## Running tests

```bash
# Full suite
php bin/phpunit

# Individual suites
php bin/phpunit tests/Unit
php bin/phpunit tests/Handler
php bin/phpunit tests/Integration

# Readable names
php bin/phpunit --testdox
```

## Integration test setup

Integration tests use `ApiTestCase`, which:

1. Creates the Doctrine schema once per test run (drops and re-creates)
2. Truncates the `emails` table before each test to ensure isolation

The test database URL is set in `.env.test`:

```
DATABASE_URL="postgresql://app:secret@127.0.0.1:5433/email_dispatch_test?serverVersion=16&charset=utf8"
```

Doctrine appends `_test` to the database name in test env, so the actual database created is `email_dispatch_test_test`.

Start postgres with `docker compose up -d postgres` before running integration tests locally.

## Messenger in tests

The `messenger.yaml` overrides the transport to `in-memory://` in the test environment:

```yaml
when@test:
    framework:
        messenger:
            transports:
                async: 'in-memory://'
```

This means messages are dispatched but not consumed automatically. The integration tests verify that messages are dispatched by checking the email status via the GET endpoint (status is `queued`, meaning the handler has not yet run), which is correct — we test the API contract, not the async worker, in those tests.

Handler behavior is tested directly in `tests/Handler/SendEmailHandlerTest.php` with mocked dependencies.

## What each test covers

### `Unit/Enum/EmailStatusTest`
- Terminal vs non-terminal status classification
- Valid and invalid transitions via `canTransitionTo()` (data-driven with `#[DataProvider]`)

### `Unit/Entity/EmailTest`
- Initial state on construction
- `markAsProcessing`, `markAsSent`, `markAsFailed`, `markAsDelivered`, `markAsBounced`
- `retryCount` increments correctly on each failure

### `Unit/Provider/ProviderResultTest`
- `success()` factory populates correct fields
- `failure()` factory populates correct fields

### `Unit/Provider/ProviderSelectorTest`
- Preferred provider is placed first in the ordered list
- Falls back to default when preferred is null
- Log provider is always appended as last fallback
- Unknown preferred provider name falls back to default

### `Handler/SendEmailHandlerTest`
- Successful send: marks `sent`, sets `provider` and `providerMessageId`
- First provider fails, second succeeds: fallback works
- All providers fail: marks `failed`, increments `retryCount`
- Email not found: throws `UnrecoverableMessageHandlingException` (no retry)
- Email already terminal: skips processing (idempotent)
- Provider throws exception (not `ProviderResult::failure`): fallback to next provider

### `Integration/EmailControllerTest`
- `POST /api/emails` returns 202 with `id` and `status=queued`
- Validation: missing fields return 422 with field-level errors
- Validation: invalid email format returns 422
- Malformed JSON returns 400
- `GET /api/emails/{id}` returns the correct record
- `GET /api/emails/{unknown-uuid}` returns 404
- `GET /api/emails/{not-a-uuid}` returns 404
- `Idempotency-Key` header: second request with same key returns same `id`

### `Integration/WebhookControllerTest`
- `delivered` event updates status to `delivered`
- `bounced` event updates status to `bounced` and stores error message
- Wrong `X-Webhook-Secret` returns 401
- Unknown `message-id` returns `status: ignored`

## Design notes

- Unit and Handler tests use `createStub()` for dependencies with no formal expectations, and `createMock()` only when `expects()` are set. This keeps PHPUnit 12's strict stub/mock distinction clean.
- `LogEmailProvider` is used as a real instance in `ProviderSelectorTest` (it has no external dependencies beyond a `NullLogger`).
- `ProviderSelector` is tested via its interface (`ProviderSelectorInterface`), not the concrete class, in Handler tests.
