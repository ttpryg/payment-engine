<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\ValueObject;

use Stringable;
use Ttpryg\PaymentEngine\Domain\Exception\InvalidPaymentArgumentException;

final class PaymentNumber implements Stringable
{
    public function __construct(public readonly string $value)
    {
        if (trim($value) === '') {
            throw new InvalidPaymentArgumentException('Payment number cannot be empty.');
        }
    }

    public static function generate(string $prefix = 'PAY'): self
    {
        $date = date('Ymd');
        $random = strtoupper(substr(bin2hex(random_bytes(4)), 0, 6));

        return new self("{$prefix}-{$date}-{$random}");
    }

    public function __toString(): string
    {
        return $this->value;
    }
}
