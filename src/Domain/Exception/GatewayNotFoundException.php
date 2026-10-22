<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Exception;

use RuntimeException;

class GatewayNotFoundException extends RuntimeException implements ExceptionInterface
{
    public static function forName(string $name): self
    {
        return new self("Payment gateway driver not registered: '{$name}'");
    }
}
