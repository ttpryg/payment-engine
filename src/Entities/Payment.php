<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Entities;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Enums\PaymentMethod;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;
use Ttpryg\PaymentEngine\ValueObjects\PaymentNumber;

class Payment
{
    public PaymentNumber $paymentNumber;

    public PaymentMethod $method;

    public PaymentStatus $status;

    public Money $amount;

    public Money $fee;

    public Money $totalAmount;

    public Money $paidAmount;

    public Money $refundedAmount;

    public function __construct(
        public readonly string $id,
        PaymentNumber|string $paymentNumber,
        public readonly PayableReference $payable,
        public readonly ?PayerReference $payer = null,
        PaymentMethod|string $method = PaymentMethod::BANK_TRANSFER,
        public string $gatewayProvider = 'manual',
        PaymentStatus|string $status = PaymentStatus::PENDING,
        Money|int $amount = 0,
        Money|int $fee = 0,
        ?Money $totalAmount = null,
        ?Money $paidAmount = null,
        ?Money $refundedAmount = null,
        public ?DateTimeImmutable $expiresAt = null,
        public ?DateTimeImmutable $paidAt = null,
        public ?array $metadata = null,
        public ?DateTimeImmutable $createdAt = null,
        public ?DateTimeImmutable $updatedAt = null
    ) {
        $currency = $amount instanceof Money ? $amount->currency : 'IDR';

        $this->paymentNumber = is_string($paymentNumber) ? new PaymentNumber($paymentNumber) : $paymentNumber;
        $this->method = is_string($method) ? PaymentMethod::from($method) : $method;
        $this->status = is_string($status) ? PaymentStatus::from($status) : $status;

        $this->amount = is_int($amount) ? new Money($amount, $currency) : $amount;
        $this->fee = is_int($fee) ? new Money($fee, $this->amount->currency) : $fee;

        $this->totalAmount = $totalAmount ?? $this->amount->add($this->fee);
        $this->paidAmount = $paidAmount ?? Money::zero($this->totalAmount->currency);
        $this->refundedAmount = $refundedAmount ?? Money::zero($this->totalAmount->currency);

        $this->createdAt = $createdAt ?? new DateTimeImmutable;
        $this->updatedAt = $updatedAt ?? new DateTimeImmutable;
    }

    public function getRemainingBalance(): Money
    {
        if ($this->paidAmount->isGreaterThanOrEqual($this->totalAmount)) {
            return Money::zero($this->totalAmount->currency);
        }

        return $this->totalAmount->subtract($this->paidAmount);
    }

    public function getRefundableAmount(): Money
    {
        if ($this->refundedAmount->isGreaterThanOrEqual($this->paidAmount)) {
            return Money::zero($this->paidAmount->currency);
        }

        return $this->paidAmount->subtract($this->refundedAmount);
    }

    public function canRefund(Money $money): bool
    {
        return $money->amount > 0 && $money->isLessThanOrEqual($this->getRefundableAmount());
    }

    public function isPaid(): bool
    {
        return $this->status === PaymentStatus::COMPLETED ||
            ($this->totalAmount->amount > 0 && $this->paidAmount->isGreaterThanOrEqual($this->totalAmount));
    }
}
