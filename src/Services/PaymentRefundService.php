<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Services;

use DateTimeImmutable;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentRefundRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Entities\PaymentRefund;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Enums\RefundStatus;
use Ttpryg\PaymentEngine\Events\PaymentPartiallyRefundedEvent;
use Ttpryg\PaymentEngine\Events\PaymentRefundedEvent;
use Ttpryg\PaymentEngine\Exceptions\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Exceptions\RefundAmountExceededException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class PaymentRefundService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentRefundRepositoryInterface $refundRepo,
        private readonly PaymentHistoryRepositoryInterface $historyRepo,
        private readonly ?GatewayManager $gatewayManager = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null
    ) {}

    public function issueRefund(
        string $paymentId,
        Money $amount,
        ?string $reason = null,
        ?string $actorType = null,
        ?string $actorId = null
    ): PaymentRefund {
        $payment = $this->paymentRepo->findById($paymentId);
        if (! $payment instanceof Payment) {
            throw PaymentNotFoundException::forId($paymentId);
        }

        if (! $payment->canRefund($amount)) {
            throw RefundAmountExceededException::create($amount, $payment->getRefundableAmount());
        }

        $gatewayRefundId = null;
        if ($this->gatewayManager instanceof GatewayManager && $this->gatewayManager->has($payment->gatewayProvider)) {
            $gateway = $this->gatewayManager->get($payment->gatewayProvider);
            $gatewayResponse = $gateway->refund($payment, $amount, $reason);
            $gatewayRefundId = $gatewayResponse->gatewayRefundId;
        }

        $refundId = 'ref-'.bin2hex(random_bytes(8));
        $refundNumber = 'RFD-'.date('Ymd').'-'.strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        $refund = new PaymentRefund(
            id: $refundId,
            paymentId: $payment->id,
            refundNumber: $refundNumber,
            amount: $amount,
            reason: $reason,
            status: RefundStatus::COMPLETED,
            gatewayRefundId: $gatewayRefundId,
            actorType: $actorType,
            actorId: $actorId
        );

        $this->refundRepo->save($refund);

        $fromStatus = $payment->status;
        $payment->refundedAmount = $payment->refundedAmount->add($amount);

        $isFullRefund = $payment->refundedAmount->isGreaterThanOrEqual($payment->paidAmount);
        $targetStatus = $isFullRefund ? PaymentStatus::REFUNDED : PaymentStatus::PARTIALLY_REFUNDED;

        $payment->status = $targetStatus;
        $payment->updatedAt = new DateTimeImmutable;
        $this->paymentRepo->save($payment);

        $history = new PaymentHistory(
            id: 'pay-hist-'.bin2hex(random_bytes(8)),
            paymentId: $payment->id,
            action: PaymentHistoryAction::REFUND_ISSUED,
            fromStatus: $fromStatus,
            toStatus: $targetStatus,
            actorType: $actorType,
            actorId: $actorId,
            note: "Refund issued: {$amount->amount} {$amount->currency}. Reason: {$reason}",
            metadata: ['refund_id' => $refund->id, 'refund_number' => $refund->refundNumber]
        );
        $this->historyRepo->save($history);

        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            if ($isFullRefund) {
                $this->eventDispatcher->dispatch(new PaymentRefundedEvent($payment, $refund));
            } else {
                $this->eventDispatcher->dispatch(new PaymentPartiallyRefundedEvent($payment, $refund));
            }
        }

        return $refund;
    }
}
