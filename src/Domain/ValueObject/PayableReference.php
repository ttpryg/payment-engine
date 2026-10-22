<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\ValueObject;

use Ttpryg\PaymentEngine\Domain\Exception\InvalidPaymentArgumentException;

final class PayableReference
{
    public function __construct(
        public readonly string $type,
        public readonly string $id
    ) {
        if (trim($type) === '' || trim($id) === '') {
            throw new InvalidPaymentArgumentException('Payable type and ID cannot be empty.');
        }
    }

    public function equals(PayableReference $payableReference): bool
    {
        return $this->type === $payableReference->type && $this->id === $payableReference->id;
    }
}
