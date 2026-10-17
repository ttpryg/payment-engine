<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Entities\Payment;

interface PaymentRepositoryInterface
{
    public function save(Payment $payment): void;

    public function findById(string $id): ?Payment;

    /**
     * Retrieve a payment row while holding a pessimistic write lock
     * (`SELECT ... FOR UPDATE`) for the duration of the active transaction.
     * When no transaction is active, or the driver does not support row
     * locking (e.g. SQLite), it falls back to a plain read.
     */
    public function findByIdForUpdate(string $id): ?Payment;

    public function findByPaymentNumber(string $paymentNumber): ?Payment;

    /**
     * Find pending payments whose `expires_at` is before the given cutoff.
     *
     * @return Payment[]
     */
    public function findPendingExpired(DateTimeImmutable $cutoff): array;

    /**
     * Find pending payments whose `expires_at` is at or before the given cutoff,
     * i.e. payments approaching (or past) expiration that should be reconciled
     * against the gateway before being auto-cancelled.
     *
     * @return Payment[]
     */
    public function findPendingExpiringBefore(DateTimeImmutable $cutoff): array;

    /**
     * @return Payment[]
     */
    public function findByPayable(string $payableType, string $payableId): array;

    /**
     * @return Payment[]
     */
    public function findByPayer(string $payerType, string $payerId): array;

    public function delete(string $id): bool;
}
