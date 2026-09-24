<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\DTOs;

use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

final class GatewayStatusResponse
{
    public function __construct(
        public readonly PaymentStatus $mappedStatus,
        public readonly ?string $transactionReference = null,
        public readonly ?Money $paidAmount = null,
        public readonly ?array $rawResponse = null
    ) {}
}
