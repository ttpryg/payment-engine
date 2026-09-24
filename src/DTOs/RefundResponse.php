<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\DTOs;

use Ttpryg\PaymentEngine\ValueObjects\Money;

final class RefundResponse
{
    public function __construct(
        public readonly bool $isSuccessful,
        public readonly ?string $gatewayRefundId = null,
        public readonly ?Money $refundedAmount = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorMessage = null
    ) {}
}
