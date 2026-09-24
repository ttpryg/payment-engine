<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Enums;

enum AttemptStatus: string
{
    case PENDING = 'pending';
    case SUCCESSFUL = 'successful';
    case FAILED = 'failed';
}
