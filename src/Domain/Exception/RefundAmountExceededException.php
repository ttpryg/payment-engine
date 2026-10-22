<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Exception;

use RuntimeException;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

class RefundAmountExceededException extends RuntimeException implements ExceptionInterface
{
    public static function create(Money $requested, Money $refundable): self
    {
        return new self("Requested refund amount ({$requested->amount} {$requested->currency}) exceeds refundable balance ({$refundable->amount} {$refundable->currency})");
    }
}
