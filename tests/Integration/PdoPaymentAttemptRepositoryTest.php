<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use DateTimeImmutable;
use PDOException;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentAttempt;
use Ttpryg\PaymentEngine\Domain\Enum\AttemptStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentAttemptRepository;

class PdoPaymentAttemptRepositoryTest extends PdoRepositoryTestCase
{
    private PdoPaymentAttemptRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoPaymentAttemptRepository($this->pdo);
    }

    public function test_save_find_update_and_list_attempts(): void
    {
        $this->seedPayment();

        $attempt = new PaymentAttempt(
            id: 'att-1',
            paymentId: 'pay-1',
            gatewayProvider: 'mock',
            transactionReference: 'tx-1',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR'),
            rawRequest: ['gateway' => 'mock'],
            rawResponse: ['id' => 'tx-1', 'status' => 'created'],
            createdAt: new DateTimeImmutable('2026-10-22 10:00:00')
        );

        $this->repository->save($attempt);

        $found = $this->repository->findById('att-1');
        $this->assertNotNull($found);
        $this->assertSame('pay-1', $found->paymentId);
        $this->assertSame('mock', $found->gatewayProvider);
        $this->assertSame('tx-1', $found->transactionReference);
        $this->assertSame(AttemptStatus::PENDING, $found->status);
        $this->assertSame(100000, $found->amount->amount);
        $this->assertSame(['gateway' => 'mock'], $found->rawRequest);
        $this->assertSame(['id' => 'tx-1', 'status' => 'created'], $found->rawResponse);
        $this->assertSame('2026-10-22 10:00:00', $found->createdAt->format('Y-m-d H:i:s'));

        $attempt->status = AttemptStatus::SUCCESSFUL;
        $attempt->transactionReference = 'tx-1-updated';
        $this->repository->save($attempt);

        $updated = $this->repository->findById('att-1');
        $this->assertNotNull($updated);
        $this->assertSame(AttemptStatus::SUCCESSFUL, $updated->status);
        $this->assertSame('tx-1-updated', $updated->transactionReference);
        $this->assertCount(1, $this->repository->findByPaymentId('pay-1'));

        $second = new PaymentAttempt(
            id: 'att-2',
            paymentId: 'pay-1',
            gatewayProvider: 'mock',
            transactionReference: 'tx-2',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR'),
            createdAt: new DateTimeImmutable('2026-10-22 10:05:00')
        );
        $this->repository->save($second);

        $this->assertCount(2, $this->repository->findByPaymentId('pay-1'));
        $this->assertSame('att-1', $this->repository->findByTransactionReference('mock', 'tx-1-updated')?->id);
        $this->assertSame('att-2', $this->repository->findByTransactionReference('mock', 'tx-2')?->id);
        $this->assertNull($this->repository->findByTransactionReference('mock', 'missing'));
        $this->assertNull($this->repository->findById('missing'));
    }

    public function test_attempt_requires_an_existing_payment(): void
    {
        $this->pdo->exec('PRAGMA foreign_keys = ON');

        $this->expectException(PDOException::class);

        $this->repository->save(new PaymentAttempt(
            id: 'att-orphan',
            paymentId: 'missing',
            gatewayProvider: 'mock',
            transactionReference: 'tx-orphan',
            status: AttemptStatus::PENDING,
            amount: new Money(1000, 'IDR')
        ));
    }
}
