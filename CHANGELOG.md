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

### Changed

- `PdoWebhookEventRepository::save()` now detects PDO duplicate-key violations
  (`SQLSTATE 23000` and driver-specific codes) and throws `DuplicateWebhookException`
  instead of leaking a raw `PDOException`.
- `WebhookProcessorService::process()` treats a `DuplicateWebhookException` from the
  initial webhook record as a safe idempotent no-op, returning the verification result
  without applying duplicate side-effects (status change, history, events).
- README webhook idempotency section and database schema snippet updated to document
  the unique constraints.

### Fixed

- Eliminated the race condition where two identical parallel webhook deliveries could
  both pass the application-level idempotency checks and be recorded twice.

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
