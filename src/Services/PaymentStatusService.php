<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Services;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentCancelledEvent;
use Ttpryg\PaymentEngine\Events\PaymentCompletedEvent;
use Ttpryg\PaymentEngine\Events\PaymentExpiredEvent;
use Ttpryg\PaymentEngine\Events\PaymentFailedEvent;
use Ttpryg\PaymentEngine\Events\PaymentStatusChangedEvent;
use Ttpryg\PaymentEngine\Exceptions\InvalidStatusTransitionException;
use Ttpryg\PaymentEngine\Exceptions\PaymentNotFoundException;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class PaymentStatusService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentHistoryRepositoryInterface $historyRepo,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function changeStatus(
        Payment $payment,
        PaymentStatus $targetStatus,
        ?string $actorType = null,
        ?string $actorId = null,
        ?string $note = null,
        ?array $metadata = null,
        ?Money $paidAmount = null
    ): Payment {
        if ($payment->status === $targetStatus) {
            return $payment;
        }

        if (! $payment->status->canTransitionTo($targetStatus)) {
            throw InvalidStatusTransitionException::create($payment->status, $targetStatus);
        }

        $fromStatus = $payment->status;
        $payment->status = $targetStatus;
        $payment->updatedAt = new DateTimeImmutable;

        if ($targetStatus === PaymentStatus::COMPLETED) {
            $payment->paidAt = new DateTimeImmutable;
            if ($paidAmount instanceof Money) {
                $payment->paidAmount = $paidAmount;
            } elseif ($payment->paidAmount->isZero()) {
                $payment->paidAmount = $payment->totalAmount;
            }
        }

        $this->paymentRepo->save($payment);

        $history = new PaymentHistory(
            id: 'pay-hist-'.bin2hex(random_bytes(8)),
            paymentId: $payment->id,
            action: PaymentHistoryAction::STATUS_CHANGED,
            fromStatus: $fromStatus,
            toStatus: $targetStatus,
            actorType: $actorType,
            actorId: $actorId,
            note: $note,
            metadata: $metadata
        );
        $this->historyRepo->save($history);

        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->eventDispatcher->dispatch(new PaymentStatusChangedEvent($payment, $fromStatus, $targetStatus));

            match ($targetStatus) {
                PaymentStatus::COMPLETED => $this->eventDispatcher->dispatch(new PaymentCompletedEvent($payment)),
                PaymentStatus::FAILED => $this->eventDispatcher->dispatch(new PaymentFailedEvent($payment, $note)),
                PaymentStatus::EXPIRED => $this->eventDispatcher->dispatch(new PaymentExpiredEvent($payment)),
                PaymentStatus::CANCELLED => $this->eventDispatcher->dispatch(new PaymentCancelledEvent($payment, $note)),
                default => null,
            };
        }

        return $payment;
    }

    public function complete(string $paymentId, ?Money $paidAmount = null, ?string $actorType = null, ?string $actorId = null, ?string $note = null): Payment
    {
        $payment = $this->getExistingPayment($paymentId);

        return $this->changeStatus($payment, PaymentStatus::COMPLETED, $actorType, $actorId, $note, paidAmount: $paidAmount);
    }

    public function fail(string $paymentId, ?string $reason = null, ?string $actorType = null, ?string $actorId = null): Payment
    {
        $payment = $this->getExistingPayment($paymentId);

        return $this->changeStatus($payment, PaymentStatus::FAILED, $actorType, $actorId, $reason);
    }

    public function expire(string $paymentId, ?string $actorType = null, ?string $actorId = null): Payment
    {
        $payment = $this->getExistingPayment($paymentId);

        return $this->changeStatus($payment, PaymentStatus::EXPIRED, $actorType, $actorId, 'Payment expired');
    }

    public function cancel(string $paymentId, ?string $reason = null, ?string $actorType = null, ?string $actorId = null): Payment
    {
        $payment = $this->getExistingPayment($paymentId);

        return $this->changeStatus($payment, PaymentStatus::CANCELLED, $actorType, $actorId, $reason);
    }

    private function getExistingPayment(string $paymentId): Payment
    {
        $payment = $this->paymentRepo->findById($paymentId);
        if (! $payment instanceof Payment) {
            throw PaymentNotFoundException::forId($paymentId);
        }

        return $payment;
    }
}
