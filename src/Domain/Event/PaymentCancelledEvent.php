<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Event;

use Ttpryg\PaymentEngine\Domain\Entity\Payment;

class PaymentCancelledEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly ?string $reason = null
    ) {}
}
