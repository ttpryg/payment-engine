<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Enums;

enum PaymentStatus: string
{
    case PENDING = 'pending';
    case AUTHORIZED = 'authorized';
    case CAPTURED = 'captured';
    case COMPLETED = 'completed';
    case FAILED = 'failed';
    case CANCELLED = 'cancelled';
    case EXPIRED = 'expired';
    case PARTIALLY_REFUNDED = 'partially_refunded';
    case REFUNDED = 'refunded';

    public function canTransitionTo(self $target): bool
    {
        if ($this === $target) {
            return true;
        }

        return match ($this) {
            self::PENDING => in_array($target, [
                self::AUTHORIZED,
                self::CAPTURED,
                self::COMPLETED,
                self::FAILED,
                self::CANCELLED,
                self::EXPIRED,
            ], true),
            self::AUTHORIZED => in_array($target, [
                self::CAPTURED,
                self::COMPLETED,
                self::CANCELLED,
                self::EXPIRED,
                self::FAILED,
            ], true),
            self::CAPTURED => in_array($target, [
                self::COMPLETED,
                self::FAILED,
                self::CANCELLED,
            ], true),
            self::COMPLETED => in_array($target, [
                self::PARTIALLY_REFUNDED,
                self::REFUNDED,
            ], true),
            self::PARTIALLY_REFUNDED => in_array($target, [
                self::PARTIALLY_REFUNDED,
                self::REFUNDED,
            ], true),
            self::FAILED, self::CANCELLED, self::EXPIRED, self::REFUNDED => false,
        };
    }
}
