<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Exception;

use RuntimeException;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;

class InvalidStatusTransitionException extends RuntimeException implements ExceptionInterface
{
    public static function create(PaymentStatus $from, PaymentStatus $to): self
    {
        return new self("Cannot transition payment status from '{$from->value}' to '{$to->value}'");
    }
}
