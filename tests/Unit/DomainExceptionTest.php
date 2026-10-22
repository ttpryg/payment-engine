<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Domain\Exception\ExceptionInterface;
use Ttpryg\PaymentEngine\Domain\Exception\InvalidPaymentArgumentException;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayerReference;
use Ttpryg\PaymentEngine\Domain\ValueObject\PaymentNumber;

class DomainExceptionTest extends TestCase
{
    public function test_value_object_validation_errors_are_library_exceptions(): void
    {
        $invalidValueObjects = [
            fn (): Money => new Money(-1),
            fn (): Money => (new Money(1, 'IDR'))->add(new Money(1, 'USD')),
            fn (): PayableReference => new PayableReference('', 'id'),
            fn (): PayerReference => new PayerReference('type', ''),
            fn (): PaymentNumber => new PaymentNumber(''),
        ];

        foreach ($invalidValueObjects as $createInvalidValueObject) {
            try {
                $createInvalidValueObject();
                $this->fail('Expected a value object validation exception.');
            } catch (ExceptionInterface $exception) {
                $this->assertInstanceOf(InvalidPaymentArgumentException::class, $exception);
            }
        }
    }
}
