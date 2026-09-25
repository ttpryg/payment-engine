<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\ValueObjects;

use InvalidArgumentException;

final class PayerReference
{
    public function __construct(
        public readonly string $type,
        public readonly string $id
    ) {
        if (trim($type) === '' || trim($id) === '') {
            throw new InvalidArgumentException('Payer type and ID cannot be empty.');
        }
    }

    public function equals(PayerReference $payerReference): bool
    {
        return $this->type === $payerReference->type && $this->id === $payerReference->id;
    }
}
