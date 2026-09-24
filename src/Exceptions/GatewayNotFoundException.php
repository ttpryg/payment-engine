<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;

class GatewayNotFoundException extends RuntimeException
{
    public static function forName(string $name): self
    {
        return new self("Payment gateway driver not registered: '{$name}'");
    }
}
