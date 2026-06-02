# Architecture

## Directory structure

```
src/
├── Controller/
│   ├── EmailController.php       POST /api/emails, GET /api/emails/{id}
│   └── WebhookController.php     POST /api/webhooks/mailgun
├── DTO/
│   ├── SubmitEmailRequest.php    Input DTO with Validator constraints
│   └── EmailResponse.php         Output DTO (serialized to snake_case JSON)
├── Entity/
│   └── Email.php                 Doctrine entity; owns all status transitions
├── Enum/
│   └── EmailStatus.php           Backed string enum; canTransitionTo() logic
├── Handler/
│   └── SendEmailHandler.php      Messenger message handler; drives send lifecycle
├── Message/
│   └── SendEmailMessage.php      Async command dispatched to RabbitMQ
├── Provider/
│   ├── EmailProviderInterface.php
│   ├── ProviderSelectorInterface.php
│   ├── ProviderSelector.php       Ordered fallback list builder
│   ├── ProviderResult.php         Value object returned by every provider
│   ├── LogEmailProvider.php       Structured log only; always the final fallback
│   ├── SmtpEmailProvider.php      Symfony Mailer
│   └── MailgunEmailProvider.php   Mailgun HTTP API (fake mode available)
├── Repository/
│   └── EmailRepository.php        Extends ServiceEntityRepository
└── Service/
    └── EmailSubmitService.php     Idempotency check, persist, dispatch
```

## Request lifecycle

```
1. POST /api/emails
   → EmailController::submit()
     → parse JSON
     → validate SubmitEmailRequest (Symfony Validator)
     → EmailSubmitService::submit()
       → check idempotency key (return existing if duplicate)
       → new Email(status=queued)
       → EmailRepository::save() + EntityManager::flush()
       → MessageBus::dispatch(SendEmailMessage)
     → return 202 with EmailResponse
```

```
2. Messenger worker consumes SendEmailMessage
   → SendEmailHandler::__invoke()
     → load Email from DB (throw UnrecoverableMessageHandlingException if not found)
     → if status is already terminal: skip (idempotent handler)
     → email.markAsProcessing(); flush()
     → ProviderSelector::getOrderedProviders() → [preferred, ..., log]
     → for each provider:
         result = provider.send(email)
         if result.success:
           email.markAsSent(provider, messageId); flush(); return
         else: continue to next
     → email.markAsFailed(lastError); flush()
     → (Messenger retries up to 3× with exponential back-off)
```

```
3. POST /api/webhooks/mailgun
   → WebhookController::mailgun()
     → verify X-Webhook-Secret header
     → extract event type and message-id from payload
     → find Email by providerMessageId
     → delivered → email.markAsDelivered()
     → failed/rejected → email.markAsFailed()
     → complained/bounced → email.markAsBounced()
     → flush()
     → return 200
```

## Dependency injection

`ProviderSelector` is wired explicitly in `services.yaml` to receive an ordered list of provider services. `SendEmailHandler` depends on `ProviderSelectorInterface`, keeping it decoupled from the concrete selector.

`WebhookController` receives `$mailgunWebhookSecret` as a scalar string argument, resolved from `%app.mailgun.webhook_secret%`, itself resolved from `MAILGUN_WEBHOOK_SECRET` env var.

## Database schema

Table: `emails`

| Column | Type | Notes |
|---|---|---|
| id | uuid | UUID v7, PK |
| recipient_email | varchar(320) | RFC 5321 max |
| subject | varchar(998) | RFC 5322 max |
| html_body | text | |
| text_body | text | nullable |
| metadata | jsonb | freeform caller metadata |
| idempotency_key | varchar(255) | nullable, indexed |
| preferred_provider | varchar(64) | nullable |
| status | varchar(32) | enum values |
| provider | varchar(64) | nullable, which provider sent it |
| provider_message_id | varchar(255) | nullable, indexed (webhook lookup) |
| retry_count | integer | increments on each markAsFailed() |
| last_error | text | nullable, last failure message |
| request_id | varchar(64) | nullable, correlation ID |
| created_at | timestamptz | |
| updated_at | timestamptz | |
| processed_at | timestamptz | nullable, set on markAsSent() |

## Messenger transport configuration

```yaml
transports:
  async:
    dsn: '%env(MESSENGER_TRANSPORT_DSN)%'
    retry_strategy:
      max_retries: 3
      delay: 1000
      multiplier: 2
  failed: 'doctrine://default?queue_name=failed'
```

Failed messages (after 3 retries) go to the `failed` Doctrine transport. Inspect with:

```bash
php bin/console messenger:failed:show
php bin/console messenger:failed:retry
```
