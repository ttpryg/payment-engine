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

    public function add(Money $money): self
    {
        $this->ensureSameCurrency($money);

        return new self($this->amount + $money->amount, $this->currency);
    }

    public function subtract(Money $money): self
    {
        $this->ensureSameCurrency($money);
        $result = $this->amount - $money->amount;
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

    public function equals(Money $money): bool
    {
        return $this->amount === $money->amount && $this->currency === $money->currency;
    }

    public function isGreaterThan(Money $money): bool
    {
        $this->ensureSameCurrency($money);

        return $this->amount > $money->amount;
    }

    public function isGreaterThanOrEqual(Money $money): bool
    {
        $this->ensureSameCurrency($money);

        return $this->amount >= $money->amount;
    }

    public function isLessThan(Money $money): bool
    {
        $this->ensureSameCurrency($money);

        return $this->amount < $money->amount;
    }

    public function isLessThanOrEqual(Money $money): bool
    {
        $this->ensureSameCurrency($money);

        return $this->amount <= $money->amount;
    }

    public function isZero(): bool
    {
        return $this->amount === 0;
    }

    public static function zero(string $currency = 'IDR'): self
    {
        return new self(0, $currency);
    }

    private function ensureSameCurrency(Money $money): void
    {
        if ($this->currency !== $money->currency) {
            throw new InvalidArgumentException("Cannot operate on different currencies: {$this->currency} and {$money->currency}");
        }
    }
}
