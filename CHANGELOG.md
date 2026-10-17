# Changelog

All notable changes to `ttpryg/payment-engine` will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

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

### Fixed

- Eliminated the race condition where two identical parallel webhook deliveries could
  both pass the application-level idempotency checks and be recorded twice.
- Eliminated partial writes (inconsistent database state) when audit history
  persistence fails after a payment, attempt, or refund write.
- Eliminated lost updates and over-refunds caused by concurrent status changes or
  refund requests reading stale payment rows (pessimistic locking).

### Documentation

- Documented the recommended asynchronous webhook ingestion architecture
  (acknowledge → enqueue → background worker) in README §7.1, including queue options,
  worker wiring, listener guidance, and idempotency guarantees.

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

[Unreleased]: https://github.com/ttpryg/payment-engine/compare/1.0.1...HEAD
[1.0.1]: https://github.com/ttpryg/payment-engine/compare/1.0.0...1.0.1
[1.0.0]: https://github.com/ttpryg/payment-engine/releases/tag/1.0.0
