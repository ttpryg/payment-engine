<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Event;

use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;

class PaymentStatusChangedEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentStatus $fromStatus,
        public readonly PaymentStatus $toStatus
    ) {}
}
