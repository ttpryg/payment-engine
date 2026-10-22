<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Exception;

use RuntimeException;

class PaymentNotFoundException extends RuntimeException implements ExceptionInterface
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
