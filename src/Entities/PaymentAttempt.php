<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Entities;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class PaymentAttempt
{
    public AttemptStatus $status;

    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $gatewayProvider,
        public ?string $transactionReference,
        AttemptStatus|string $status,
        public readonly Money $amount,
        public ?array $rawRequest = null,
        public ?array $rawResponse = null,
        public ?DateTimeImmutable $createdAt = null
    ) {
        $this->status = is_string($status) ? AttemptStatus::from($status) : $status;
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
    }
}
