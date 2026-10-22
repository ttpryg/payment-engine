# Changelog

All notable changes to `ttpryg/payment-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Added

- `ttpryg/database-migrator` dependency and PHP class migrations:
  - `202610180001_create_payments_tables.php`
  - `202610180002_add_payment_indexes.php`
- `DatabaseMigrator` is now a thin wrapper around the package `MigrationRunner` and
  exposes `migrate()`, `rollback()`, and `appliedMigrations()`.
- `Application\DTO\CreatePaymentRequest` encapsulating all payment creation
  parameters.

### Changed

- Declared `psr/event-dispatcher` as a direct runtime dependency so the PSR-14
  contract used by the services is explicit and version-bound, and removed the
  unused `ttpryg/event-dispatcher` dependency.
- Added PHPStan level 8 static analysis (`phpstan.neon`) and the `composer analyse`
  and `composer cs-check` scripts; CI now runs static analysis before linting.
- Entity timestamps (`createdAt` and `updatedAt`) are now non-nullable after
  construction, and `CurlHttpClient` rejects empty HTTP method names.
- `PaymentService::createPayment()` now accepts a single `CreatePaymentRequest`
  object instead of twelve individual parameters (breaking for direct callers).
- Database schema is now created and upgraded exclusively through migrations; the
  raw `database/schema.sql` snapshot was removed.
- Integration tests now bootstrap their schema through `DatabaseMigrator` instead of
  executing `schema.sql`.
- Separated the module into explicit `Domain` and `Application` layers following the
  module architecture conventions:
  - `Entities` → `Domain\Entity`
  - `ValueObjects` → `Domain\ValueObject`
  - `Enums` → `Domain\Enum`
  - `Events` → `Domain\Event`
  - `Exceptions` → `Domain\Exception`
  - `Services` → `Application\Service`
  - `DTOs` → `Application\DTO`
  - Repository interfaces moved from `Contracts` to `Domain\Repository`.
- `Contracts` now only exposes the gateway (`PaymentGatewayInterface`) and HTTP
  (`HttpClientInterface`) ports.
- Moved concrete infrastructure adapters out of the module core into a sibling
  `infrastructure/` directory:
  - `Repositories\Pdo` → `Infrastructure\Persistence\Pdo`
  - `Repositories\Memory` → `Infrastructure\Persistence\InMemory`
  - `Gateways` → `Infrastructure\Gateway`
  - `Http` → `Infrastructure\Http`
  - `Database` → `Infrastructure\Database`
- Registered the `Ttpryg\PaymentEngine\Infrastructure\` PSR-4 namespace in
  `composer.json`.
- README: replaced the bundled-driver documentation with a step-by-step guide for
  implementing and registering a custom gateway driver.

### Removed

- Removed the bundled `MidtransGateway` and `XenditGateway` provider-specific drivers
  (and their dedicated tests). The engine remains provider-agnostic; integrate a
  provider by implementing `PaymentGatewayInterface` or extending `AbstractHttpGateway`.
- Removed the custom `Infrastructure\Database\MigrationRunner`, the raw SQL migration
  `database/migrations/001_add_payment_indexes.sql`, and `database/schema.sql`.

## [1.2.0] - 2026-10-18

### Added

- Root `ExceptionInterface` marker interface (`Ttpryg\PaymentEngine\Exceptions\ExceptionInterface`)
  that extends `Throwable` and is implemented by every custom exception, so consumers can
  catch all library-specific errors with a single type.
- Versioned migration system: `DatabaseMigrator` and `MigrationRunner` (tracker table
  `payment_engine_migrations`), the baseline migration
  `database/migrations/001_add_payment_indexes.sql`, and
  `tests/Integration/DatabaseMigratorTest.php`.
- `.editorconfig` for consistent editor settings across the project.

### Changed

- Added `declare(strict_types=1)` to all source files that were missing it.
- README: documented programmatic schema application via `DatabaseMigrator::migrate()`.

## [1.1.0] - 2026-10-18

### Added

- **Database-level webhook idempotency.** Added `UNIQUE` indexes on `webhook_events`
  for `(gateway_provider, payload_fingerprint)` (`uq_webhook_fingerprint`) and
  `(gateway_provider, event_id)` (`uq_webhook_event_id`) to close the TOCTOU race
  window when a gateway delivers the same webhook concurrently.
- `DuplicateWebhookException::forDuplicate()` factory to represent a duplicate
  detected by the database unique constraints (carries both event ID and fingerprint).
- `ManagesTransactions` concern providing a shared `runInTransaction()` helper that
  joins an already-active transaction (PDO has no nested transactions).
- Optional `?PDO $pdo` dependency on `PaymentStatusService`, `PaymentRefundService`,
  and `WebhookProcessorService` to enable ACID transaction wrapping.
- `PaymentRepositoryInterface::findByIdForUpdate()` (implemented by the PDO and Memory
  repositories) which issues `SELECT ... FOR UPDATE` while a transaction is active,
  falling back to a plain read when no transaction is active or the driver does not
  support row locking (SQLite).
- `PaymentRepositoryInterface::findPendingExpired()` (implemented by the PDO and Memory
  repositories) to fetch pending payments whose `expires_at` is before a given cutoff.
- `PaymentExpirationService` with `expireOverdue()` and `countExpirable()` for
  auto-cancelling overdue pending payments from a scheduled worker/cron. Payments that
  changed state after the snapshot query are skipped instead of aborting the batch.
- `PaymentRepositoryInterface::findPendingExpiringBefore()` (implemented by the PDO and
  Memory repositories) to fetch pending payments approaching (or past) expiration.
- `GatewayReconciliationService` with `reconcile()` and `countReconcilable()` to poll
  `PaymentGatewayInterface::checkStatus()` for pending payments nearing expiry, recover
  dropped webhooks, and sync local payment/attempt status. Gateway/network failures and
  concurrently-updated payments are skipped.
- `HttpClientInterface` and `HttpResponse` DTO providing a pluggable HTTP transport
  abstraction, plus a dependency-free `CurlHttpClient` implementation and
  `HttpClientException` for transport failures.
- `AbstractHttpGateway` base class (JSON requests, Basic auth, constant-time helpers,
  error extraction) for HTTP-based gateway drivers.
- `MidtransGateway` implementing `PaymentGatewayInterface` for the Midtrans Snap + Core
  APIs (sandbox/production), including SHA-512 `signature_key` webhook verification and
  payment status mapping.
- `XenditGateway` implementing `PaymentGatewayInterface` for the Xendit Invoice + Refund
  APIs, including constant-time `x-callback-token` webhook verification and status mapping.
- `PaymentEdgeCaseTest` integration coverage: TOCTOU webhook race resolved by the
  database unique constraint (no-op, no duplicate history/events), a partial-refund
  ladder that reaches exactly `paid_amount` then rejects overflow, and reconciliation
  gracefully skipping a payment when the real Midtrans driver hits a transport timeout.

### Changed

- `PdoWebhookEventRepository::save()` now detects PDO duplicate-key violations
  (`SQLSTATE 23000` and driver-specific codes) and throws `DuplicateWebhookException`
  instead of leaking a raw `PDOException`.
- `WebhookProcessorService::process()` treats a `DuplicateWebhookException` from the
  initial webhook record as a safe idempotent no-op, returning the verification result
  without applying duplicate side-effects (status change, history, events).
- README webhook idempotency section and database schema snippet updated to document
  the unique constraints.
- `PaymentStatusService::changeStatus()`, `PaymentRefundService::issueRefund()`, and
  `WebhookProcessorService::process()` now wrap their database mutations
  (payment/attempt/refund/webhook/history writes) in `beginTransaction()`/`commit()`
  and roll back on any failure.
- Status-change and refund domain events are now dispatched only after a successful
  commit.
- `PaymentService` now reuses the shared `ManagesTransactions` trait instead of its own
  private transaction helper.
- `PaymentStatusService::changeStatus()` and `PaymentRefundService::issueRefund()` now
  lock and reload the payment row inside the transaction before validating, so
  concurrent transitions/refunds operate on the latest committed state.
- `WebhookProcessorService` now continues processing with the payment instance returned
  by the (locked) status change.
- Documented the recommended asynchronous webhook ingestion architecture
  (acknowledge → enqueue → background worker) in README §7.1, including queue options,
  worker wiring, listener guidance, and idempotency guarantees.

### Fixed

- Eliminated the race condition where two identical parallel webhook deliveries could
  both pass the application-level idempotency checks and be recorded twice.
- Eliminated partial writes (inconsistent database state) when audit history
  persistence fails after a payment, attempt, or refund write.
- Eliminated lost updates and over-refunds caused by concurrent status changes or
  refund requests reading stale payment rows (pessimistic locking).

## [1.0.1] - 2026-09-28

### Fixed

- Exclude the `created_at` parameter on `UPDATE` statements in the PDO repositories for
  SQLite compatibility.

## [1.0.0] - 2026-09-25

### Added

- Initial release: payment lifecycle state machine, partial/full refunds, gateway
  abstraction (`PaymentGatewayInterface` + `GatewayManager`), idempotent webhook
  processing, append-only audit history, PSR-14 domain events, and PDO/In-Memory
  storage drivers.

[Unreleased]: https://github.com/ttpryg/payment-engine/compare/1.2.0...HEAD
[1.2.0]: https://github.com/ttpryg/payment-engine/compare/1.1.0...1.2.0
[1.1.0]: https://github.com/ttpryg/payment-engine/compare/1.0.1...1.1.0
[1.0.1]: https://github.com/ttpryg/payment-engine/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/ttpryg/payment-engine/releases/tag/1.0.0
