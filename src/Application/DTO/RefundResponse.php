<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\DTO;

use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

final class RefundResponse
{
    /**
     * @param  array<string, mixed>|null  $rawResponse
     */
    public function __construct(
        public readonly bool $isSuccessful,
        public readonly ?string $gatewayRefundId = null,
        public readonly ?Money $refundedAmount = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorMessage = null
    ) {}
}
