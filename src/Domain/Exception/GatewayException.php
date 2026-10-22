<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Exception;

use RuntimeException;

class GatewayException extends RuntimeException implements ExceptionInterface
{
    public static function failed(string $gateway, string $message, ?\Throwable $throwable = null): self
    {
        return new self("Gateway [{$gateway}] error: {$message}", 0, $throwable);
    }
}
