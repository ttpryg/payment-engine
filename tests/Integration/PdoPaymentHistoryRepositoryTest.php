<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use DateTimeImmutable;
use PDOException;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentHistoryRepository;

class PdoPaymentHistoryRepositoryTest extends PdoRepositoryTestCase
{
    private PdoPaymentHistoryRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoPaymentHistoryRepository($this->pdo);
    }

    public function test_save_and_find_histories_ordered_by_creation_time(): void
    {
        $this->seedPayment();

        $this->repository->save(new PaymentHistory(
            id: 'hist-2',
            paymentId: 'pay-1',
            action: PaymentHistoryAction::STATUS_CHANGED,
            fromStatus: PaymentStatus::PENDING,
            toStatus: PaymentStatus::COMPLETED,
            actorType: 'gateway',
            actorId: 'mock',
            note: 'Payment completed',
            metadata: ['webhook_event_id' => 'wh-1'],
            createdAt: new DateTimeImmutable('2026-10-22 12:05:00')
        ));

        $this->repository->save(new PaymentHistory(
            id: 'hist-1',
            paymentId: 'pay-1',
            action: PaymentHistoryAction::PAYMENT_CREATED,
            toStatus: PaymentStatus::PENDING,
            actorType: 'system',
            actorId: 'checkout',
            note: 'Payment created',
            metadata: ['source' => 'test'],
            createdAt: new DateTimeImmutable('2026-10-22 12:00:00')
        ));

        $histories = $this->repository->findByPaymentId('pay-1');

        $this->assertCount(2, $histories);
        $this->assertSame('hist-1', $histories[0]->id);
        $this->assertSame('hist-2', $histories[1]->id);
        $this->assertSame(PaymentHistoryAction::PAYMENT_CREATED, $histories[0]->action);
        $this->assertSame(PaymentStatus::PENDING, $histories[0]->toStatus);
        $this->assertSame(['source' => 'test'], $histories[0]->metadata);
        $this->assertSame(PaymentStatus::PENDING, $histories[1]->fromStatus);
        $this->assertSame(PaymentStatus::COMPLETED, $histories[1]->toStatus);
        $this->assertSame('gateway', $histories[1]->actorType);
        $this->assertSame('mock', $histories[1]->actorId);
        $this->assertSame('Payment completed', $histories[1]->note);
        $this->assertSame(['webhook_event_id' => 'wh-1'], $histories[1]->metadata);
        $this->assertCount(0, $this->repository->findByPaymentId('missing'));
    }

    public function test_history_is_append_only_and_rejects_duplicate_ids(): void
    {
        $this->seedPayment();

        $history = new PaymentHistory(
            id: 'hist-1',
            paymentId: 'pay-1',
            action: PaymentHistoryAction::PAYMENT_CREATED,
            toStatus: PaymentStatus::PENDING
        );

        $this->repository->save($history);

        $this->expectException(PDOException::class);

        $this->repository->save($history);
    }
}
