<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;

class PaymentNotFoundException extends RuntimeException
{
    public static function forId(string $id): self
    {
        return new self("Payment not found with ID: {$id}");
    }

    public static function forPaymentNumber(string $paymentNumber): self
    {
        return new self("Payment not found with Payment Number: {$paymentNumber}");
    }
}
