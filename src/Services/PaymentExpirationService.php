<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Services;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Exceptions\InvalidStatusTransitionException;
use Ttpryg\PaymentEngine\Exceptions\PaymentNotFoundException;

class PaymentExpirationService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentStatusService $paymentStatusService
    ) {}

    /**
     * Expire every pending payment whose `expires_at` is before the cutoff.
     *
     * Intended to be invoked from a scheduled worker/cron job. Payments that
     * changed state (e.g. completed or cancelled) between the query and the
     * transition are skipped instead of aborting the whole batch.
     *
     * @return Payment[] the payments that were transitioned to `expired`
     */
    public function expireOverdue(
        ?DateTimeImmutable $cutoff = null,
        string $actorType = 'system',
        ?string $actorId = 'expiration_worker'
    ): array {
        $expired = [];

        foreach ($this->paymentRepository->findPendingExpired($cutoff ?? new DateTimeImmutable) as $payment) {
            try {
                $expired[] = $this->paymentStatusService->expire($payment->id, $actorType, $actorId);
            } catch (InvalidStatusTransitionException|PaymentNotFoundException) {
                // The payment was completed/cancelled (or removed) after the
                // snapshot query; skip it for this run.
                continue;
            }
        }

        return $expired;
    }

    public function countExpirable(?DateTimeImmutable $cutoff = null): int
    {
        return count($this->paymentRepository->findPendingExpired($cutoff ?? new DateTimeImmutable));
    }
}
