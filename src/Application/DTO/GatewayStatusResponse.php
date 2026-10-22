<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\DTO;

use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

final class GatewayStatusResponse
{
    public function __construct(
        public readonly PaymentStatus $mappedStatus,
        public readonly ?string $transactionReference = null,
        public readonly ?Money $paidAmount = null,
        public readonly ?array $rawResponse = null
    ) {}
}
