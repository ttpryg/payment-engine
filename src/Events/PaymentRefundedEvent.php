<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Events;

use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentRefund;

class PaymentRefundedEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly PaymentRefund $refund
    ) {}
}
