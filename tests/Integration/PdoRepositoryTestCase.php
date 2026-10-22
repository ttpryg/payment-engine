<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Infrastructure\Database\DatabaseMigrator;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentRepository;

abstract class PdoRepositoryTestCase extends TestCase
{
    protected PDO $pdo;

    protected PdoPaymentRepository $paymentRepository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        (new DatabaseMigrator($this->pdo))->migrate();

        $this->paymentRepository = new PdoPaymentRepository($this->pdo);
    }

    protected function seedPayment(string $id = 'pay-1', string $paymentNumber = 'PAY-1'): void
    {
        $this->paymentRepository->save(new Payment(
            id: $id,
            paymentNumber: $paymentNumber,
            payable: new PayableReference('order', 'ord-'.$id),
            amount: 100000
        ));
    }
}
