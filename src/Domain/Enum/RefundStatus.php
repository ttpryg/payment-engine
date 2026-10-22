<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Enum;

enum RefundStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
