<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\Service;

use DateTimeImmutable;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\PaymentEngine\Concerns\ManagesTransactions;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Event\PaymentCancelledEvent;
use Ttpryg\PaymentEngine\Domain\Event\PaymentCompletedEvent;
use Ttpryg\PaymentEngine\Domain\Event\PaymentExpiredEvent;
use Ttpryg\PaymentEngine\Domain\Event\PaymentFailedEvent;
use Ttpryg\PaymentEngine\Domain\Event\PaymentStatusChangedEvent;
use Ttpryg\PaymentEngine\Domain\Exception\InvalidStatusTransitionException;
use Ttpryg\PaymentEngine\Domain\Exception\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

class PaymentStatusService
{
    use ManagesTransactions;

    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentHistoryRepositoryInterface $paymentHistoryRepository,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?PDO $pdo = null
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
        $fromStatus = null;

        $updatedPayment = $this->runInTransaction(
            $this->pdo,
            function () use ($payment, $targetStatus, $actorType, $actorId, $note, $metadata, $paidAmount, &$fromStatus): Payment {
                // Acquire a pessimistic lock on the row so concurrent
                // transitions read the latest committed state.
                $lockedPayment = $this->paymentRepository->findByIdForUpdate($payment->id) ?? $payment;

                if ($lockedPayment->status === $targetStatus) {
                    return $lockedPayment;
                }

                if (! $lockedPayment->status->canTransitionTo($targetStatus)) {
                    throw InvalidStatusTransitionException::create($lockedPayment->status, $targetStatus);
                }

                $fromStatus = $lockedPayment->status;
                $lockedPayment->status = $targetStatus;
                $lockedPayment->updatedAt = new DateTimeImmutable;

                if ($targetStatus === PaymentStatus::COMPLETED) {
                    $lockedPayment->paidAt = new DateTimeImmutable;
                    if ($paidAmount instanceof Money) {
                        $lockedPayment->paidAmount = $paidAmount;
                    } elseif ($lockedPayment->paidAmount->isZero()) {
                        $lockedPayment->paidAmount = $lockedPayment->totalAmount;
                    }
                }

                $paymentHistory = new PaymentHistory(
                    id: 'pay-hist-'.bin2hex(random_bytes(8)),
                    paymentId: $lockedPayment->id,
                    action: PaymentHistoryAction::STATUS_CHANGED,
                    fromStatus: $fromStatus,
                    toStatus: $targetStatus,
                    actorType: $actorType,
                    actorId: $actorId,
                    note: $note,
                    metadata: $metadata
                );

                $this->paymentRepository->save($lockedPayment);
                $this->paymentHistoryRepository->save($paymentHistory);

                return $lockedPayment;
            }
        );

        if ($fromStatus instanceof PaymentStatus && $this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->eventDispatcher->dispatch(new PaymentStatusChangedEvent($updatedPayment, $fromStatus, $targetStatus));

            match ($targetStatus) {
                PaymentStatus::COMPLETED => $this->eventDispatcher->dispatch(new PaymentCompletedEvent($updatedPayment)),
                PaymentStatus::FAILED => $this->eventDispatcher->dispatch(new PaymentFailedEvent($updatedPayment, $note)),
                PaymentStatus::EXPIRED => $this->eventDispatcher->dispatch(new PaymentExpiredEvent($updatedPayment)),
                PaymentStatus::CANCELLED => $this->eventDispatcher->dispatch(new PaymentCancelledEvent($updatedPayment, $note)),
                default => null,
            };
        }

        return $updatedPayment;
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
        $payment = $this->paymentRepository->findById($paymentId);
        if (! $payment instanceof Payment) {
            throw PaymentNotFoundException::forId($paymentId);
        }

        return $payment;
    }
}
