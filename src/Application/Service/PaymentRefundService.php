<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\Service;

use DateTimeImmutable;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\PaymentEngine\Concerns\ManagesTransactions;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentRefund;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Enum\RefundStatus;
use Ttpryg\PaymentEngine\Domain\Event\PaymentPartiallyRefundedEvent;
use Ttpryg\PaymentEngine\Domain\Event\PaymentRefundedEvent;
use Ttpryg\PaymentEngine\Domain\Exception\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Domain\Exception\RefundAmountExceededException;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentRefundRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;

class PaymentRefundService
{
    use ManagesTransactions;

    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentRefundRepositoryInterface $paymentRefundRepository,
        private readonly PaymentHistoryRepositoryInterface $paymentHistoryRepository,
        private readonly ?GatewayManager $gatewayManager = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?PDO $pdo = null
    ) {}

    public function issueRefund(
        string $paymentId,
        Money $money,
        ?string $reason = null,
        ?string $actorType = null,
        ?string $actorId = null
    ): PaymentRefund {
        $payment = null;
        $isFullRefund = false;

        $paymentRefund = $this->runInTransaction(
            $this->pdo,
            function () use ($paymentId, $money, $reason, $actorType, $actorId, &$payment, &$isFullRefund): PaymentRefund {
                // Lock the payment row and re-validate against the latest
                // committed balance to prevent concurrent over-refunds.
                $payment = $this->paymentRepository->findByIdForUpdate($paymentId);
                if (! $payment instanceof Payment) {
                    throw PaymentNotFoundException::forId($paymentId);
                }

                if (! $payment->canRefund($money)) {
                    throw RefundAmountExceededException::create($money, $payment->getRefundableAmount());
                }

                $gatewayRefundId = null;
                if ($this->gatewayManager instanceof GatewayManager && $this->gatewayManager->has($payment->gatewayProvider)) {
                    $gateway = $this->gatewayManager->get($payment->gatewayProvider);
                    $gatewayResponse = $gateway->refund($payment, $money, $reason);
                    $gatewayRefundId = $gatewayResponse->gatewayRefundId;
                }

                $refundId = 'ref-'.bin2hex(random_bytes(8));
                $refundNumber = 'RFD-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

                $paymentRefund = new PaymentRefund(
                    id: $refundId,
                    paymentId: $payment->id,
                    refundNumber: $refundNumber,
                    amount: $money,
                    reason: $reason,
                    status: RefundStatus::COMPLETED,
                    gatewayRefundId: $gatewayRefundId,
                    actorType: $actorType,
                    actorId: $actorId
                );

                $fromStatus = $payment->status;
                $payment->refundedAmount = $payment->refundedAmount->add($money);

                $isFullRefund = $payment->refundedAmount->isGreaterThanOrEqual($payment->paidAmount);
                $targetStatus = $isFullRefund ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;

                $payment->status = $targetStatus;
                $payment->updatedAt = new DateTimeImmutable;

                $paymentHistory = new PaymentHistory(
                    id: 'pay-hist-'.bin2hex(random_bytes(8)),
                    paymentId: $payment->id,
                    action: PaymentHistoryAction::REFUND_ISSUED,
                    fromStatus: $fromStatus,
                    toStatus: $targetStatus,
                    actorType: $actorType,
                    actorId: $actorId,
                    note: "Refund issued: {$money->amount} {$money->currency}. Reason: {$reason}",
                    metadata: ['refund_id' => $paymentRefund->id, 'refund_number' => $paymentRefund->refundNumber]
                );

                $this->paymentRefundRepository->save($paymentRefund);
                $this->paymentRepository->save($payment);
                $this->paymentHistoryRepository->save($paymentHistory);

                return $paymentRefund;
            }
        );

        if ($this->eventDispatcher instanceof EventDispatcherInterface && $payment instanceof Payment) {
            if ($isFullRefund) {
                $this->eventDispatcher->dispatch(new PaymentRefundedEvent($payment, $paymentRefund));
            } else {
                $this->eventDispatcher->dispatch(new PaymentPartiallyRefundedEvent($payment, $paymentRefund));
            }
        }

        return $paymentRefund;
    }
}
