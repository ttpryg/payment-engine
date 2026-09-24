<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;

class InvalidStatusTransitionException extends RuntimeException
{
    public static function create(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self("Cannot transition payment status from '{$from->value}' to '{$to->value}'");
    }
}
