<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Entities;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Enums\RefundStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class PaymentRefund
{
    public RefundStatus $status;

    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $refundNumber,
        public readonly Money $amount,
        public ?string $reason = null,
        RefundStatus|string $status = RefundStatus::COMPLETED,
        public ?string $gatewayRefundId = null,
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?DateTimeImmutable $createdAt = null
    ) {
        $this->status = is_string($status) ? RefundStatus::from($status) : $status;
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
    }
}
