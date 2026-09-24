<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\ValueObjects;

use InvalidArgumentException;

final class Money
{
    public function __construct(
        public readonly int $amount,
        public readonly string $currency = 'IDR'
    ) {
        if ($amount < 0) {
            throw new InvalidArgumentException('Money amount cannot be negative.');
        }
    }

    public function add(Money $other): self
    {
        $this->ensureSameCurrency($other);

        return new self($this->amount + $other->amount, $this->currency);
    }

    public function subtract(Money $other): self
    {
        $this->ensureSameCurrency($other);
        $result = $this->amount - $other->amount;
        if ($result < 0) {
            throw new InvalidArgumentException('Subtraction results in negative money amount.');
        }

        return new self($result, $this->currency);
    }

    public function multiply(int|float $multiplier): self
    {
        if ($multiplier < 0) {
            throw new InvalidArgumentException('Multiplier cannot be negative.');
        }

        return new self((int) round($this->amount * $multiplier), $this->currency);
    }

    public function equals(Money $other): bool
    {
        return $this->amount === $other->amount && $this->currency === $other->currency;
    }

    public function isGreaterThan(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amount > $other->amount;
    }

    public function isGreaterThanOrEqual(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amount >= $other->amount;
    }

    public function isLessThan(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amount < $other->amount;
    }

    public function isLessThanOrEqual(Money $other): bool
    {
        $this->ensureSameCurrency($other);

        return $this->amount <= $other->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public static function zero(string $currency = 'IDR'): self
    {
        return new self(0, $currency);
    }

    private function ensureSameCurrency(Money $other): void
    {
        if ($this->currency !== $other->currency) {
            throw new InvalidArgumentException("Cannot operate on different currencies: {$this->currency} and {$other->currency}");
        }
    }
}
