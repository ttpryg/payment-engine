<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Event;

use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentRefund;

class PaymentRefundedEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentRefund $refund
    ) {}
}
