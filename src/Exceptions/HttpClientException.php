<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;

class HttpClientException extends RuntimeException
{
    public static function transportError(string $message, ?\Throwable $previous = null): self
    {
        return new self("HTTP transport error: {$message}", 0, $previous);
    }
}
