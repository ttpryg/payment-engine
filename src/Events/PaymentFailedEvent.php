<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Events;

use Ttpryg\PaymentEngine\Entities\Payment;

class PaymentFailedEvent
{
    public function __construct(
        public readonly Payment $payment,
        public readonly ?string $reason = null
    ) {}
}
