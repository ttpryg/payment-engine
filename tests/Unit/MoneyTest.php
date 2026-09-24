<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use InvalidArgumentException;
use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class MoneyTest extends TestCase
{
    public function testMoneyCreationAndOperations(): void
    {
        $m1 = new Money(50000, 'IDR');
        $m2 = new Money(25000, 'IDR');

        $this->assertEquals(50000, $m1->amount);
        $this->assertEquals('IDR', $m1->currency);

        $sum = $m1->add($m2);
        $this->assertEquals(75000, $sum->amount);

        $diff = $m1->subtract($m2);
        $this->assertEquals(25000, $diff->amount);

        $product = $m2->multiply(2);
        $this->assertEquals(50000, $product->amount);

        $this->assertTrue($m1->isGreaterThan($m2));
        $this->assertTrue($m2->isLessThan($m1));
        $this->assertTrue($m1->equals(new Money(50000, 'IDR')));
        $this->assertFalse($m1->isZero());
        $this->assertTrue(Money::zero()->isZero());
    }

    public function testNegativeAmountThrowsException(): void
    {
        $this->expectException(InvalidArgumentException::class);
        new Money(-1000, 'IDR');
    }

    public function testDifferentCurrenciesThrowException(): void
    {
        $idr = new Money(10000, 'IDR');
        $usd = new Money(10, 'USD');

        $this->expectException(InvalidArgumentException::class);
        $idr->add($usd);
    }
}
