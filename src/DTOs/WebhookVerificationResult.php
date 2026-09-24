<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\DTOs;

use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

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
