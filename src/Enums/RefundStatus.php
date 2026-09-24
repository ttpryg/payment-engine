<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Enums;

enum RefundStatus: string
{
    case PENDING = 'pending';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
}
