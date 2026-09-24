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
- **Robust Webhook Idempotency**: Prevents duplicate executions, double settlements, or duplicate history entries using gateway `event_id` and SHA-256 `payload_fingerprint`.
- **Strict State Machine**: Enforces valid status transitions across the entire payment lifecycle (`pending`, `authorized`, `captured`, `completed`, `failed`, `cancelled`, `expired`, `partially_refunded`, `refunded`).
- **Partial & Full Refunds**: Verifies that refund requests never exceed the remaining refundable `paid_amount`.
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

class XenditPaymentGateway implements PaymentGatewayInterface
{
    public function getName(): string
    {
        return 'xendit';
    }

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse
    {
        // Call Xendit API using $payment->totalAmount->amount
        return new GatewayResponse(
            isSuccessful: true,
            transactionReference: 'xendit_invoice_123',
            redirectUrl: 'https://checkout.xendit.co/web/123'
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        // Validate signature token
        return new WebhookVerificationResult(
            isValid: true,
            transactionReference: $payload['id'] ?? null,
            eventId: $payload['event_id'] ?? null,
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
$gatewayManager->register(new XenditPaymentGateway());
```

---

## 🛡️ 7. Idempotent Webhook Processing

Webhooks sent by gateways are often redelivered multiple times. `WebhookProcessorService` prevents duplicate balance updates and duplicate domain events using:
1. **Gateway Event ID**: Tracks unique delivery IDs supplied by the gateway.
2. **Payload Fingerprint**: Computes SHA-256 hashes of the payload:
   ```php
   $fingerprint = WebhookEvent::generateFingerprint($payload);
   ```

If a webhook with the same `event_id` or `payload_fingerprint` has already been processed, it returns immediately without firing duplicate events or inserting duplicate history entries.

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

---

## 🗄️ 9. Database Schema

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

CREATE INDEX IF NOT EXISTS idx_webhook_fingerprint ON webhook_events (gateway_provider, payload_fingerprint);
CREATE INDEX IF NOT EXISTS idx_webhook_event_id ON webhook_events (gateway_provider, event_id);
```

---

## 🚀 10. Quick Usage Example

```php
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
$statusService    = new PaymentStatusService($paymentRepo, $historyRepo);
$paymentService   = new PaymentService($paymentRepo, $attemptRepo, $historyRepo, $gatewayManager, $statusService, pdo: $pdo);
$refundService    = new PaymentRefundService($paymentRepo, $refundRepo, $historyRepo, $gatewayManager);
$webhookProcessor = new WebhookProcessorService($gatewayManager, $webhookRepo, $paymentRepo, $attemptRepo, $historyRepo, $statusService);

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
```

---

## 🧪 11. Testing

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

## 📄 12. License

MIT License.
