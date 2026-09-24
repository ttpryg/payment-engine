<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;

class DuplicateWebhookException extends RuntimeException
{
    public static function forFingerprint(string $gateway, string $fingerprint): self
    {
        return new self("Duplicate webhook received from [{$gateway}] with fingerprint: {$fingerprint}");
    }

    public static function forEventId(string $gateway, string $eventId): self
    {
        return new self("Duplicate webhook received from [{$gateway}] with event ID: {$eventId}");
    }
}
