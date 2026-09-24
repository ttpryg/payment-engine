<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\ValueObjects;

use InvalidArgumentException;
use Stringable;

final class PaymentNumber implements Stringable
{
    public function __construct(public readonly string $value)
    {
        if (trim($value) === '') {
            throw new InvalidArgumentException('Payment number cannot be empty.');
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
