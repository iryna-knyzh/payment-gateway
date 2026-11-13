# Payment Gateway

A small payment-orchestration service in **Symfony 6.4 / PHP 8.3 / PostgreSQL**.
It accepts payments through one API and routes them to payment providers whose APIs
are all different, then keeps the payment status correct even when the network
and the providers misbehave.

The project focuses on the problems that make payment integrations hard:

| Problem | Solution in this project |
|---|---|
| Client retries `POST /payments` → double charge | `Idempotency-Key` header + unique index, request fingerprint |
| Every provider has its own API, units, codes, signatures | `PaymentProviderInterface` (Adapter) + `ProviderRegistry` (Strategy, tagged service locator) |
| Provider timed out — was the card charged? | Timeout ≠ failure: payment stays `processing`, a delayed status check asks the provider |
| Forged webhook "payment succeeded" | HMAC-SHA256 signature check with `hash_equals()`, empty secret rejects everything |
| Provider delivers the same webhook twice | Webhook inbox with unique `(provider, event_id)` |
| `failed` webhook arrives after `succeeded` | Symfony Workflow state machine rejects illegal transitions |
| API request, webhook worker and status checker update one payment at once | Transaction + `SELECT … FOR UPDATE` (`LockMode::PESSIMISTIC_WRITE`) |
| `0.1 + 0.2 != 0.3` | Amounts are integers in minor units; decimal strings only at provider boundary |
| `LIMIT … OFFSET 1000000` is slow | Keyset pagination over time-ordered UUID v7 |

## Architecture

```
                 POST /api/payments                         ┌──────────────┐
  Client ──────────────────────────► PaymentController ───► │PaymentService│
                                                            └──────┬───────┘
                                                1. save payment    │  (idempotency key, unique index)
                                                2. call provider   ▼  (outside DB transaction)
                                                  ┌──────────────────────────┐
                                                  │ ProviderRegistry         │  Strategy
                                                  │  ├─ FakeBankProvider     │  Adapter: JSON, minor units
                                                  │  └─ QuickPayProvider     │  Adapter: form, "10.50", codes
                                                  └──────────────────────────┘
                                                3. PaymentStatusUpdater  ── row lock + state machine
                                                   timeout? ──► CheckPaymentStatus (delayed message)

  Provider ── POST /webhooks/{provider} ──► WebhookController: verify signature
                                            ─► WebhookInbox: store once ─► queue ─► 200 OK
                                                                              │
                         messenger:consume ◄──────────────────────────────────┘
                          ├─ ProcessWebhookEventHandler ─► PaymentStatusUpdater
                          └─ CheckPaymentStatusHandler  ─► provider->fetchStatus() ─► PaymentStatusUpdater
```

Payment state machine (`config/packages/workflow.yaml`):

```
new ──submit──► processing ──succeed──► succeeded
 │                  │
 └──────fail────────┴──────fail───────► failed
```

### Code map

```
src/
├── Controller/        PaymentController (API), WebhookController
├── Api/               request DTO (validated), JSON view
├── Entity/            Payment, WebhookEvent
├── Payment/           PaymentService, PaymentStatusUpdater, Money/MinorUnits
├── Provider/          PaymentProviderInterface, ProviderRegistry, DTOs, exceptions
│   ├── FakeBank/      modern JSON API
│   └── QuickPay/      legacy form API with result codes
├── Webhook/           WebhookInbox
├── Message/           ProcessWebhookEvent, CheckPaymentStatus
└── MessageHandler/
simulator/index.php    fake providers for local development (separate process)
```

## Running

### With Docker

```bash
docker compose up -d --build
docker compose run --rm app composer install
docker compose run --rm app bin/console doctrine:migrations:migrate -n
```

### Without Docker

Requires PHP 8.3+ with `pdo_pgsql` and PostgreSQL. Point `DATABASE_URL` to it in `.env.local`, then:

```bash
composer install
bin/console doctrine:migrations:migrate -n
php -S localhost:8000 -t public          # the gateway
php -S localhost:8081 simulator/index.php # the providers
bin/console messenger:consume async -vv   # the worker
```

## Try it

```bash
# 1. Create a payment
curl -s -X POST localhost:8000/api/payments \
  -H 'Content-Type: application/json' -H 'Idempotency-Key: order-1001' \
  -d '{"amount": 1050, "currency": "UAH", "provider": "fakebank"}'
# → 201 {"id": "01a1…", "status": "processing", "amount": 1050, "amount_formatted": "10.50", …}

# 2. Retry the same request → 200, same payment, header Idempotent-Replayed: true, provider NOT called again

# 3. The "bank" approves it and sends a webhook (twice, to show de-duplication)
curl -s -X POST "localhost:8081/_control/complete?payment_id=<id>&result=success&duplicate=1"

# 4. After the worker processes it
curl -s localhost:8000/api/payments/<id>    # → "status": "succeeded"

# List, newest first, cursor-based
curl -s "localhost:8000/api/payments?limit=20"
curl -s "localhost:8000/api/payments?limit=20&after=<next_cursor>"
```

Simulator behaviour by amount: `…13` (e.g. `1013`) → declined, `…42` → the provider
answers after 12 s, so the gateway times out and recovers through a status check.
Providers: `fakebank`, `quickpay`.

## Tests

```bash
composer check      # PHPStan (level 6) + PHPUnit
```

- **Unit**: money conversion, both providers (status mapping, timeouts vs rejections,
  signature verification, tampered webhooks) using `MockHttpClient`.
- **Functional**: the whole flow through HTTP → DB → queue → worker, with the
  network replaced by `framework.http_client.mock_response_factory`
  and the queue by the `in-memory://` transport. Tests run on SQLite, no services needed.

## Design notes

- **Provider call outside DB transactions.** A network call can take 10 s; holding row locks
  that long would block everyone else touching the payment.
- **Persist before calling the provider.** If the process dies mid-request, there is a
  payment record to reconcile instead of a charge nobody knows about.
- **Resetting the EntityManager.** After a unique-constraint violation Doctrine closes the
  EntityManager; `ManagerRegistry::resetManager()` gets a usable one.
- **`base_uri` must end with `/`.** With `http://host/fakebank` + `/v1/charges` RFC 3986 drops
  `/fakebank`. Request paths are relative for that reason (this bug was caught by running
  the gateway against the simulator).
- **Webhooks answer fast.** Verify → store → queue → 200. Processing happens in a worker with
  retries and a `failed` transport.
- **Compatibility.** Targets Symfony 6.4 LTS and deliberately avoids 7.x-only features
  (e.g. `#[TaggedLocator]` instead of `#[AutowireLocator]`). Needs 6.3+ for `#[MapRequestPayload]`
  and `#[Autowire(env: …)]`; on 6.0–6.3 `Route` lives in `Routing\Annotation` instead of `Routing\Attribute`.

## Not production-ready (on purpose)

What a real system would add:

- **Transactional outbox**: if the process dies between storing a webhook and dispatching the
  message, the event stays unprocessed. Fix: dispatch from the same DB transaction or re-queue
  `webhook_event.processed_at IS NULL` periodically.
- **Reconciliation** against providers' daily settlement reports; stuck `new` payments.
- **Authentication** of API clients (merchants), rate limiting.
- **Kafka / SQS** instead of the Doctrine transport (only the DSN changes).
- **Secrets** via Symfony secrets / vault instead of env defaults; PCI-DSS: card data never
  touches this service (tokenised by the provider).
- **Observability**: metrics per provider (latency, error rate, decline rate), Sentry, alerts
  on payments pending too long.
- php-fpm + nginx instead of the PHP built-in server.
