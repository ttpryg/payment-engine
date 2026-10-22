<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Entity;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Domain\Enum\AttemptStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

class PaymentAttempt
{
    public AttemptStatus $status;

    public DateTimeImmutable $createdAt;

    /**
     * @param  array<string, mixed>|null  $rawRequest
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        public readonly string $gatewayProvider,
        public ?string $transactionReference,
        AttemptStatus|string $status,
        public readonly Money $amount,
        public ?array $rawRequest = null,
        public ?array $rawResponse = null,
        ?DateTimeImmutable $createdAt = null
    ) {
        $this->status = is_string($status) ? AttemptStatus::from($status) : $status;
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
    }
}
