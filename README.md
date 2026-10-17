# PaymentEngine Library (`ttpryg/payment-engine`)

`ttpryg/payment-engine` is a production-ready, framework-agnostic standalone PHP 8.1+ Payment Engine designed to manage payment lifecycles, polymorphic payable/payer references, gateway abstraction, idempotent webhook processing, partial/full refunds, and append-only audit histories without coupling to any specific framework or external domain.

---

## 🌟 1. Introduction

In modern microservices and modular monolithic architectures, handling payments requires strict separation of domain boundaries. A Payment Engine must be responsible for orchestrating payment attempts, validating state transitions, guaranteeing idempotency during webhook retries, and recording immutable audit logs—without possessing hardcoded dependencies on orders, shopping carts, product catalogs, or specific third-party payment gateway SDKs.

`ttpryg/payment-engine` decouples payment processing via a clean driver/adapter pattern (`PaymentGatewayInterface`) and generic references (`PayableReference` and `PayerReference`).

---

## 🚀 2. Features

- **Framework Agnostic**: Pure PHP 8.1+ with zero framework dependencies (works on Vanilla PHP, Slim, Laravel, Symfony, CodeIgniter).
- **Polymorphic / Generic References**: Decoupled from orders and users via `PayableReference` (`payable_type`, `payable_id`) and `PayerReference` (`payer_type`, `payer_id`).
- **Strict Money Representation**: Uses immutable `Money` Value Object with **integer** values (representing lowest denomination/cents or integer units for IDR), eliminating floating-point rounding errors.
- **Provider-Agnostic Gateway Abstraction**: Decouples payment gateway integration using `PaymentGatewayInterface` and `GatewayManager`.
- **Built-in Gateway Drivers**:
  - `MockPaymentGateway`: Predictable driver for unit tests, CI/CD, and local sandboxes.
  - `ManualTransferGateway`: Built-in driver for manual bank transfers, cash on delivery (COD), or offline payments.
  - `MidtransGateway`: Real Midtrans Snap + Core API driver (sandbox/production) with SHA-512 `signature_key` webhook verification.
  - `XenditGateway`: Real Xendit Invoice + Refund API driver with constant-time `x-callback-token` webhook verification.
- **Pluggable HTTP Transport**: Real drivers communicate through the `HttpClientInterface` abstraction (a bundled `CurlHttpClient` is the default), so you can swap in your own client, add retries/logging, or inject a fake without touching the driver.
- **Robust Webhook Idempotency**: Prevents duplicate executions, double settlements, or duplicate history entries using gateway `event_id` and SHA-256 `payload_fingerprint`.
- **Async-Ready Webhook Ingestion**: A recommendation for acknowledging gateways immediately, queueing the raw delivery, and processing status updates/events in a background worker (see [§7.1](#71-asynchronous-webhook-ingestion-recommended)).
- **Strict State Machine**: Enforces valid status transitions across the entire payment lifecycle (`pending`, `authorized`, `captured`, `completed`, `failed`, `cancelled`, `expired`, `partially_refunded`, `refunded`).
- **Partial & Full Refunds**: Verifies that refund requests never exceed the remaining refundable `paid_amount`.
- **ACID Transactional Mutations**: When a `PDO` connection is provided, status changes, refunds, and webhook processing run inside a transaction so payment, refund, attempt, and audit writes either commit together or roll back atomically.
- **Pessimistic Row Locking**: Critical mutations acquire a `SELECT ... FOR UPDATE` row lock inside the transaction (via `findByIdForUpdate()`) to prevent concurrent over-refunds and lost status updates. Drivers without row-lock support (e.g. SQLite) fall back to a plain read.
- **Payment Expiration & Auto-Cancel**: `PaymentExpirationService` finds pending payments past their `expires_at` and transitions them to `expired`, ready to be driven by a cron or scheduled worker.
- **Gateway Status Reconciliation**: `GatewayReconciliationService` polls `PaymentGatewayInterface::checkStatus()` for pending payments approaching expiry to recover dropped webhooks (network timeouts, firewall drops) and syncs the local status accordingly.
- **Append-Only Audit History**: Tracks every critical transition and webhook with `actor_type`, `actor_id`, and structured `metadata`.
- **PSR-14 Domain Events**: Dispatches standardized domain events (`PaymentCreatedEvent`, `PaymentStatusChangedEvent`, `PaymentCompletedEvent`, `PaymentRefundedEvent`, etc.).
- **Multiple Storage Drivers**: Clean separation via PDO (MariaDB, MySQL, SQLite) & In-Memory drivers for ultra-fast unit testing.

---

## 📦 3. Installation

Install via Composer:

```bash
composer require ttpryg/payment-engine
```

---

## 🧬 4. Domain Model

### Value Objects
- **`Money`**: Strictly integer-based amount (`int $amount`, `string $currency = 'IDR'`). Provides `add()`, `subtract()`, `multiply()`, `equals()`, `isGreaterThan()`, `isLessThan()`.
- **`PaymentNumber`**: Unique human-readable identifier (e.g., `PAY-20260925-A1B2C3`).
- **`PayableReference`**: Generic reference to the billable entity (`payable_type`, `payable_id`).
  - Example: `new PayableReference('order', 'ORD-2026-001')` or `new PayableReference('subscription', 'SUB-88')`.
- **`PayerReference`**: Generic reference to the customer/payer (`payer_type`, `payer_id`).

### Entities
1. **`Payment`**: Core aggregate root representing the payment record, containing monetary balances (`amount`, `fee`, `totalAmount`, `paidAmount`, `refundedAmount`), status, and metadata.
2. **`PaymentAttempt`**: Log of each payment attempt made against a gateway (`gateway_provider`, `transaction_reference`, `status`, raw request/response payloads).
3. **`PaymentRefund`**: Detailed record of full or partial refunds issued against a completed payment.
4. **`PaymentHistory`**: Immutable append-only audit trail capturing every status transition or webhook occurrence.
5. **`WebhookEvent`**: Audit and deduplication log capturing inbound webhook deliveries with SHA-256 fingerprinting.

---

## 🔄 5. Payment Lifecycle & State Machine

```
              ┌───────────────> CANCELLED
              │
PENDING ──────┼───────────────> EXPIRED
   │          │
   │          └───────────────> FAILED
   │
   ├── (AUTHORIZED) ──> CAPTURED ──> COMPLETED ──> PARTIALLY_REFUNDED ──> REFUNDED
   │                                     │
   └─────────────────────────────────────┘
```

- Invalid transitions (e.g. attempting to complete an already `CANCELLED` or `FAILED` payment) throw `InvalidStatusTransitionException`.
- Idempotent transitions (calling `complete()` on an already `COMPLETED` payment) result in a safe no-op without creating duplicate histories or events.

---

## 🔌 6. Gateway Driver Architecture

To add a new payment gateway (such as Midtrans, Xendit, Stripe, or DOKU), implement `PaymentGatewayInterface`:

```php
namespace MyNamespace\Gateways;

use Ttpryg\PaymentEngine\Contracts\PaymentGatewayInterface;
use Ttpryg\PaymentEngine\DTOs\GatewayResponse;
use Ttpryg\PaymentEngine\DTOs\GatewayStatusResponse;
use Ttpryg\PaymentEngine\DTOs\RefundResponse;
use Ttpryg\PaymentEngine\DTOs\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class CustomPaymentGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'custom';
    }

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse
    {
        // Call your gateway API using $payment->totalAmount->amount
        return new GatewayResponse(
            isSuccessful: true,
            transactionReference: 'tx_123',
            redirectUrl: 'https://pay.example.com/checkout/123'
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        // Validate the gateway signature/token, then map the status
        return new WebhookVerificationResult(
            isValid: true,
            transactionReference: $payload['transaction_reference'] ?? null,
            mappedStatus: \Ttpryg\PaymentEngine\Enums\PaymentStatus::COMPLETED,
            paidAmount: new Money((int) $payload['paid_amount'], 'IDR')
        );
    }

    public function checkStatus(string $transactionReference): GatewayStatusResponse
    {
        // Query gateway API
    }

    public function refund(Payment $payment, Money $amount, ?string $reason = null): RefundResponse
    {
        // Execute refund API
    }
}
```

Register drivers using `GatewayManager`:

```php
$gatewayManager = new GatewayManager();
$gatewayManager->register(new MockPaymentGateway('mock'));
$gatewayManager->register(new ManualTransferGateway('manual'));

// Real drivers (see below)
$gatewayManager->register(new MidtransGateway(getenv('MIDTRANS_SERVER_KEY')));
$gatewayManager->register(new XenditGateway(getenv('XENDIT_SECRET_KEY'), getenv('XENDIT_WEBHOOK_TOKEN')));
```

### Built-in Real Gateway Drivers

Two production drivers ship with the engine. Both extend `AbstractHttpGateway` and require only credentials; the bundled `CurlHttpClient` is used unless you inject your own `HttpClientInterface`.

#### Midtrans (`MidtransGateway`)

```php
use Ttpryg\PaymentEngine\Gateways\MidtransGateway;

$midtrans = new MidtransGateway(
    serverKey: getenv('MIDTRANS_SERVER_KEY'),
    isProduction: false, // sandbox by default
);
$gatewayManager->register($midtrans);
```

- `createTransaction()` calls the **Snap** API (`/snap/v1/transactions`) and exposes the hosted `redirect_url` plus `snap_token`.
- `checkStatus()` queries `/v2/{order_id}/status`; the payment number is used as the Midtrans `order_id`.
- `refund()` calls `/v2/{order_id}/refund` with a generated unique `refund_key`.
- `verifyWebhook()` validates `signature_key` against `hash('sha512', order_id . status_code . gross_amount . serverKey)` using a **constant-time** comparison. Mapping: `settlement` and `capture` (with `fraud_status = accept`) → `COMPLETED`, `capture` (challenged) → `PENDING`, `deny` → `FAILED`, `cancel` → `CANCELLED`, `expire` → `EXPIRED`, `refund`/`partial_refund` → `REFUNDED`/`PARTIALLY_REFUNDED`.

#### Xendit (`XenditGateway`)

```php
use Ttpryg\PaymentEngine\Gateways\XenditGateway;

$xendit = new XenditGateway(
    secretKey: getenv('XENDIT_SECRET_KEY'),
    webhookToken: getenv('XENDIT_WEBHOOK_TOKEN'),
);
$gatewayManager->register($xendit);
```

- `createTransaction()` creates an invoice via `/v2/invoices`, stores the Xendit `invoice_id` in `payment->metadata['xendit_invoice_id']` (reused for refunds), and returns the `invoice_url`.
- `checkStatus()` queries `/v2/invoices/{invoice_id}`.
- `refund()` calls the unified `/refunds` endpoint with the stored `invoice_id`.
- `verifyWebhook()` performs a **constant-time** (`hash_equals`) comparison of the `x-callback-token` header against the configured `webhookToken`. Mapping: `PAID`/`SETTLED` → `COMPLETED`, `EXPIRED` → `EXPIRED`, `FAILED` → `FAILED`, `PENDING` → `PENDING`.
- A `PayerReference` whose type is `email` is automatically forwarded as the Xendit `payer_email`.

### Custom HTTP Transport

Every real driver accepts an optional `HttpClientInterface` as its third constructor argument:

```php
use Ttpryg\PaymentEngine\Http\CurlHttpClient;

$gateway = new MidtransGateway($serverKey, false, new CurlHttpClient(timeout: 15));
```

Because drivers depend only on the `Ttpryg\PaymentEngine\Contracts\HttpClientInterface` abstraction, you can inject a PSR-18-backed adapter, add retries/logging, or supply a fake client in tests without modifying the driver. Transport failures surface as `GatewayException`.

---

## 🛡️ 7. Idempotent Webhook Processing

Webhooks sent by gateways are often redelivered multiple times. `WebhookProcessorService` prevents duplicate balance updates and duplicate domain events using:
1. **Gateway Event ID**: Tracks unique delivery IDs supplied by the gateway.
2. **Payload Fingerprint**: Computes SHA-256 hashes of the payload:
   ```php
   $fingerprint = WebhookEvent::generateFingerprint($payload);
   ```

If a webhook with the same `event_id` or `payload_fingerprint` has already been processed, it returns immediately without firing duplicate events or inserting duplicate history entries.

Idempotency is additionally enforced at the database level via unique indexes on `webhook_events (gateway_provider, payload_fingerprint)` and `webhook_events (gateway_provider, event_id)`. When two identical deliveries arrive concurrently (TOCTOU race), the losing `INSERT` raises `DuplicateWebhookException`, which `WebhookProcessorService` treats as a safe no-op.

### 7.1 Asynchronous Webhook Ingestion (Recommended)

Gateways typically expect a webhook to be acknowledged within ~3 seconds, while `WebhookProcessorService::process()` performs signature verification, database writes, and **synchronous** PSR-14 event dispatch. Slow listeners (sending emails, decrementing stock, calling third-party APIs) can cause the gateway to time out and retry.

The recommended architecture is **acknowledge first, process in the background**:

```text
Gateway ──HTTP POST──▶ Webhook Endpoint ──enqueue(raw payload)──▶ Queue (Redis / RabbitMQ / DB)
                             │                                            │
                       return HTTP 200                             Worker: verify + process
                                                                           │
                                                             WebhookProcessorService::process()
                                                                           │
                                                             status change + history + events
```

1. **Acknowledge immediately.** The HTTP endpoint should do the minimum work possible: read the raw body and headers, push them onto a durable queue (Redis, RabbitMQ, SQS, or a database-backed queue), and return `200 OK`.

   ```php
   // Framework-agnostic pseudocode for the webhook endpoint.
   $payload = json_decode(file_get_contents('php://input'), true, flags: JSON_THROW_ON_ERROR);

   $queue->push('payment.webhooks', [
       'provider' => 'midtrans',
       'payload'  => $payload,
       'headers'  => $requestHeaders,
   ]);

   http_response_code(200);
   ```

2. **Process asynchronously.** A background worker consumes the job and calls `WebhookProcessorService::process()`. Because verification runs in the worker, an invalid/tampered payload simply throws `GatewayException` and no side-effects are applied.

   ```php
   while ($job = $queue->pop('payment.webhooks')) {
       try {
           $webhookProcessor->process($job['provider'], $job['payload'], $job['headers']);
       } catch (\Ttpryg\PaymentEngine\Exceptions\GatewayException) {
           // Invalid signature / malformed payload — drop or move to a dead-letter queue.
       } catch (\Throwable) {
           $queue->retry($job); // transient failure (DB/gateway) — safe to retry (idempotent)
       }
   }
   ```

3. **Let listeners queue their own side-effects.** PSR-14 listeners registered on the engine still run synchronously *inside the worker*. For any heavy or external side-effect (email, stock, outbound webhooks), enqueue a second background job from the listener instead of doing the work inline.

   ```php
   $listenerProvider->addListener(PaymentCompletedEvent::class, function (PaymentCompletedEvent $event) use ($queue): void {
       $queue->push('orders.fulfil', ['payment_id' => $event->payment->id]);
   });
   ```

**Why it is safe:** the `event_id` + `payload_fingerprint` unique indexes make `process()` idempotent, so at-least-once delivery from the queue (retries, gateway redeliveries) never double-charges or duplicates history/events. Keep the queue delivery **at-least-once** and let the engine deduplicate.

> **Don't** persist inbound webhooks through `WebhookEventRepositoryInterface` in the HTTP request path and then call `process()` later: a stored-but-unprocessed `webhook_events` row is treated as a duplicate by the unique constraints and the later call becomes a no-op. Keep raw ingestion in your own queue/table and run `process()` exactly once per delivery.

---

## 💰 8. Partial & Full Refunds

Refunds are processed through `PaymentRefundService`:

```php
// Partial refund
$refund = $refundService->issueRefund(
    paymentId: 'pay-001',
    amount: new Money(50000, 'IDR'),
    reason: 'Defective item returned'
);

// Payment status automatically transitions to PARTIALLY_REFUNDED.
// When all paid amount has been refunded, status becomes REFUNDED.
```

If a refund request exceeds the remaining refundable amount, a `RefundAmountExceededException` is thrown.

When a `PDO` connection is supplied, refund issuance first locks the payment row (`SELECT ... FOR UPDATE`) and re-validates the refundable balance against the latest committed state, so two concurrent refund requests can never overshoot the paid amount.

---

## ⏳ 9. Payment Expiration, Reconciliation & Auto-Cancel

Pending payments that pass their `expires_at` timestamp can be swept automatically:

```php
use Ttpryg\PaymentEngine\Services\PaymentExpirationService;

$expirationService = new PaymentExpirationService($paymentRepo, $statusService);

// Transition every pending payment whose expires_at is in the past.
$expired = $expirationService->expireOverdue();

// Preview how many are eligible without mutating anything.
$count = $expirationService->countExpirable();
```

`expireOverdue()` uses `PaymentRepositoryInterface::findPendingExpired()` and calls `PaymentStatusService::expire()` for each candidate. Payments that changed state (completed or cancelled) between the snapshot query and the transition are skipped so a single batch never aborts. Invoke it from your framework scheduler, cron entry, or queue worker (for example, once per minute).

### Gateway Status Reconciliation

Gateways may fail to deliver a webhook (network timeout, firewall drop), leaving a payment stuck in `pending`. `GatewayReconciliationService` polls the gateway for pending payments approaching expiry and syncs the local status:

```php
use Ttpryg\PaymentEngine\Services\GatewayReconciliationService;

$reconciliationService = new GatewayReconciliationService(
    $gatewayManager,
    $paymentRepo,
    $attemptRepo,
    $statusService,
    pdo: $pdo,
);

// Reconcile pending payments expiring within the next 5 minutes (and any already past).
$reconciled = $reconciliationService->reconcile((new DateTimeImmutable)->modify('+5 minutes'));
```

For each candidate the service resolves the latest payment attempt with a transaction reference, calls `PaymentGatewayInterface::checkStatus()`, updates the attempt, and drives the payment status through `PaymentStatusService`. Gateway/network failures and payments that moved on concurrently are skipped, so the batch is safe to retry and should be scheduled **before** the expiration sweep.

---

## 🗄️ 10. Database Schema

Run the SQL script from `database/schema.sql`:

```sql
CREATE TABLE IF NOT EXISTS payments (
    id VARCHAR(36) PRIMARY KEY,
    payment_number VARCHAR(50) NOT NULL UNIQUE,
    payable_type VARCHAR(50) NOT NULL,
    payable_id VARCHAR(100) NOT NULL,
    payer_type VARCHAR(50) NULL,
    payer_id VARCHAR(100) NULL,
    method VARCHAR(50) NOT NULL,
    gateway_provider VARCHAR(50) NOT NULL,
    status VARCHAR(30) NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    amount BIGINT NOT NULL DEFAULT 0,
    fee BIGINT NOT NULL DEFAULT 0,
    total_amount BIGINT NOT NULL DEFAULT 0,
    paid_amount BIGINT NOT NULL DEFAULT 0,
    refunded_amount BIGINT NOT NULL DEFAULT 0,
    expires_at DATETIME NULL,
    paid_at DATETIME NULL,
    metadata TEXT NULL,
    created_at DATETIME NOT NULL,
    updated_at DATETIME NOT NULL
);

CREATE INDEX IF NOT EXISTS idx_payments_payable ON payments (payable_type, payable_id);
CREATE INDEX IF NOT EXISTS idx_payments_payer ON payments (payer_type, payer_id);
CREATE INDEX IF NOT EXISTS idx_payments_status ON payments (status);
CREATE INDEX IF NOT EXISTS idx_payments_created_at ON payments (created_at);

CREATE TABLE IF NOT EXISTS payment_attempts (
    id VARCHAR(36) PRIMARY KEY,
    payment_id VARCHAR(36) NOT NULL,
    gateway_provider VARCHAR(50) NOT NULL,
    transaction_reference VARCHAR(150) NULL,
    status VARCHAR(30) NOT NULL,
    amount BIGINT NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    raw_request TEXT NULL,
    raw_response TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payment_attempts_payment_id ON payment_attempts (payment_id);
CREATE INDEX IF NOT EXISTS idx_attempts_tx_ref ON payment_attempts (gateway_provider, transaction_reference);

CREATE TABLE IF NOT EXISTS payment_refunds (
    id VARCHAR(36) PRIMARY KEY,
    payment_id VARCHAR(36) NOT NULL,
    refund_number VARCHAR(50) NOT NULL UNIQUE,
    amount BIGINT NOT NULL,
    currency VARCHAR(3) NOT NULL DEFAULT 'IDR',
    reason TEXT NULL,
    status VARCHAR(30) NOT NULL DEFAULT 'completed',
    gateway_refund_id VARCHAR(150) NULL,
    actor_type VARCHAR(50) NULL,
    actor_id VARCHAR(100) NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payment_refunds_payment_id ON payment_refunds (payment_id);

CREATE TABLE IF NOT EXISTS payment_histories (
    id VARCHAR(36) PRIMARY KEY,
    payment_id VARCHAR(36) NOT NULL,
    action VARCHAR(50) NOT NULL,
    from_status VARCHAR(30) NULL,
    to_status VARCHAR(30) NULL,
    actor_type VARCHAR(50) NULL,
    actor_id VARCHAR(100) NULL,
    note TEXT NULL,
    metadata TEXT NULL,
    created_at DATETIME NOT NULL,
    FOREIGN KEY (payment_id) REFERENCES payments(id) ON DELETE CASCADE
);

CREATE INDEX IF NOT EXISTS idx_payment_histories_payment_id ON payment_histories (payment_id);

CREATE TABLE IF NOT EXISTS webhook_events (
    id VARCHAR(36) PRIMARY KEY,
    gateway_provider VARCHAR(50) NOT NULL,
    event_id VARCHAR(150) NULL,
    payload_fingerprint VARCHAR(64) NOT NULL,
    payload TEXT NOT NULL,
    is_processed TINYINT(1) NOT NULL DEFAULT 0,
    processed_at DATETIME NULL,
    created_at DATETIME NOT NULL
);

CREATE UNIQUE INDEX IF NOT EXISTS uq_webhook_fingerprint ON webhook_events (gateway_provider, payload_fingerprint);
CREATE UNIQUE INDEX IF NOT EXISTS uq_webhook_event_id ON webhook_events (gateway_provider, event_id);
```

---

## 🚀 11. Quick Usage Example

```php
use DateTimeImmutable;
use PDO;
use Ttpryg\PaymentEngine\Enums\PaymentMethod;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\ManualTransferGateway;
use Ttpryg\PaymentEngine\Gateways\MockPaymentGateway;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRefundRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoWebhookEventRepository;
use Ttpryg\PaymentEngine\Services\GatewayReconciliationService;
use Ttpryg\PaymentEngine\Services\PaymentExpirationService;
use Ttpryg\PaymentEngine\Services\PaymentRefundService;
use Ttpryg\PaymentEngine\Services\PaymentService;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\Services\WebhookProcessorService;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;

// 1. Setup PDO & Repositories
$pdo = new PDO('mysql:host=localhost;dbname=payment_db', 'root', 'secret');

$paymentRepo = new PdoPaymentRepository($pdo);
$attemptRepo = new PdoPaymentAttemptRepository($pdo);
$refundRepo  = new PdoPaymentRefundRepository($pdo);
$historyRepo = new PdoPaymentHistoryRepository($pdo);
$webhookRepo = new PdoWebhookEventRepository($pdo);

// 2. Setup Gateway Manager
$gatewayManager = new GatewayManager();
$gatewayManager->register(new MockPaymentGateway('mock'));
$gatewayManager->register(new ManualTransferGateway('manual'));

// 3. Initialize Services
$statusService    = new PaymentStatusService($paymentRepo, $historyRepo, pdo: $pdo);
$paymentService   = new PaymentService($paymentRepo, $attemptRepo, $historyRepo, $gatewayManager, $statusService, pdo: $pdo);
$refundService    = new PaymentRefundService($paymentRepo, $refundRepo, $historyRepo, $gatewayManager, pdo: $pdo);
$webhookProcessor = new WebhookProcessorService($gatewayManager, $webhookRepo, $paymentRepo, $attemptRepo, $historyRepo, $statusService, pdo: $pdo);
$expirationService = new PaymentExpirationService($paymentRepo, $statusService);
$reconciliationService = new GatewayReconciliationService($gatewayManager, $paymentRepo, $attemptRepo, $statusService, pdo: $pdo);

// 4. Create Payment
$payment = $paymentService->createPayment(
    id: 'pay-001',
    payable: new PayableReference('order', 'ORD-2026-001'),
    amount: 250000, // IDR 250,000 (integer)
    method: PaymentMethod::QRIS,
    gatewayProvider: 'mock',
    payer: new PayerReference('user', 'usr-100'),
    fee: 1500
);

// 5. Ingest Inbound Gateway Webhook (Safe & Idempotent)
$webhookProcessor->process('mock', [
    'event_id' => 'evt_12345',
    'transaction_reference' => $payment->metadata['redirect_url'] ?? 'mock_tx_...',
    'status' => 'completed',
    'paid_amount' => 251500,
]);

// 6. Reconcile pending payments nearing expiry against the gateway (recover dropped webhooks)
$reconciled = $reconciliationService->reconcile((new DateTimeImmutable)->modify('+5 minutes'));

// 7. Expire overdue pending payments (run from a scheduled worker/cron)
$expiredPayments = $expirationService->expireOverdue();
```

---

## 🧪 12. Testing

Run tests with PHPUnit:

```bash
vendor/bin/phpunit
```

Or using Docker Compose:

```bash
docker compose up -d
docker compose exec app vendor/bin/phpunit
```

---

## 📄 13. License

MIT License.
