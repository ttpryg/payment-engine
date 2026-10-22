<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\DTO;

use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

final class WebhookVerificationResult
{
    public function __construct(
        public readonly bool $isValid,
        public readonly ?string $transactionReference = null,
        public readonly ?string $eventId = null,
        public readonly ?PaymentStatus $mappedStatus = null,
        public readonly ?Money $paidAmount = null,
        public readonly ?string $payloadFingerprint = null,
        public readonly ?string $failureReason = null
    ) {}
}
