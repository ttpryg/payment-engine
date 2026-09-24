<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class RefundAmountExceededException extends RuntimeException
{
    public static function create(Money $requested, Money $refundable): self
    {
        return new self("Requested refund amount ({$requested->amount} {$requested->currency}) exceeds refundable balance ({$refundable->amount} {$refundable->currency})");
    }
}
