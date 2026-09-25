<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;

class GatewayException extends RuntimeException
{
    public static function failed(string $gateway, string $message, ?\Throwable $throwable = null): self
    {
        return new self("Gateway [{$gateway}] error: {$message}", 0, $throwable);
    }
}
