<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Exceptions;

use RuntimeException;

class DuplicateWebhookException extends RuntimeException implements ExceptionInterface
{
    public static function forFingerprint(string $gateway, string $fingerprint): self
    {
        return new self("Duplicate webhook received from [{$gateway}] with fingerprint: {$fingerprint}");
    }

    public static function forEventId(string $gateway, string $eventId): self
    {
        return new self("Duplicate webhook received from [{$gateway}] with event ID: {$eventId}");
    }

    public static function forDuplicate(string $gateway, ?string $eventId, string $fingerprint): self
    {
        $identity = $eventId !== null
            ? "event ID [{$eventId}] or fingerprint [{$fingerprint}]"
            : "fingerprint [{$fingerprint}]";

        return new self("Duplicate webhook received from [{$gateway}] matching {$identity}");
    }
}
