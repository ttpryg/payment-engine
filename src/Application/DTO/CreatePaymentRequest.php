<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\DTO;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentMethod;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayerReference;

final class CreatePaymentRequest
{
    /**
     * @param  array<string, mixed>|null  $metadata
     */
    public function __construct(
        public readonly string $id,
        public readonly PayableReference $payable,
        public readonly Money|int $amount,
        public readonly PaymentMethod|string $method = PaymentMethod::BANK_TRANSFER,
        public readonly string $gatewayProvider = 'manual',
        public readonly ?PayerReference $payer = null,
        public readonly Money|int $fee = 0,
        public readonly ?string $paymentNumber = null,
        public readonly ?DateTimeImmutable $expiresAt = null,
        public readonly ?array $metadata = null,
        public readonly ?string $actorType = null,
        public readonly ?string $actorId = null
    ) {}
}
