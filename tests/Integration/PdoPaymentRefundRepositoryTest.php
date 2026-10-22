<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use DateTimeImmutable;
use PDOException;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentRefund;
use Ttpryg\PaymentEngine\Domain\Enum\RefundStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentRefundRepository;

class PdoPaymentRefundRepositoryTest extends PdoRepositoryTestCase
{
    private PdoPaymentRefundRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoPaymentRefundRepository($this->pdo);
    }

    public function test_save_find_update_and_list_refunds(): void
    {
        $this->seedPayment();

        $refund = new PaymentRefund(
            id: 'rfd-1',
            paymentId: 'pay-1',
            refundNumber: 'RFD-1',
            amount: new Money(25000, 'IDR'),
            reason: 'Damaged item',
            status: RefundStatus::COMPLETED,
            gatewayRefundId: 'gw-refund-1',
            actorType: 'user',
            actorId: 'usr-1',
            createdAt: new DateTimeImmutable('2026-10-22 11:00:00')
        );

        $this->repository->save($refund);

        $found = $this->repository->findById('rfd-1');
        $this->assertNotNull($found);
        $this->assertSame('pay-1', $found->paymentId);
        $this->assertSame('RFD-1', $found->refundNumber);
        $this->assertSame(25000, $found->amount->amount);
        $this->assertSame('Damaged item', $found->reason);
        $this->assertSame(RefundStatus::COMPLETED, $found->status);
        $this->assertSame('gw-refund-1', $found->gatewayRefundId);
        $this->assertSame('2026-10-22 11:00:00', $found->createdAt->format('Y-m-d H:i:s'));

        $refund->status = RefundStatus::PENDING;
        $refund->gatewayRefundId = 'gw-refund-1-retry';
        $refund->reason = 'Damaged item (retry)';
        $this->repository->save($refund);

        $updated = $this->repository->findByRefundNumber('RFD-1');
        $this->assertNotNull($updated);
        $this->assertSame(RefundStatus::PENDING, $updated->status);
        $this->assertSame('gw-refund-1-retry', $updated->gatewayRefundId);
        $this->assertSame('Damaged item (retry)', $updated->reason);
        $this->assertCount(1, $this->repository->findByPaymentId('pay-1'));

        $this->assertNull($this->repository->findById('missing'));
        $this->assertNull($this->repository->findByRefundNumber('RFD-MISSING'));
    }

    public function test_duplicate_refund_number_is_rejected(): void
    {
        $this->seedPayment();

        $this->repository->save(new PaymentRefund(
            id: 'rfd-1',
            paymentId: 'pay-1',
            refundNumber: 'RFD-DUPLICATE',
            amount: new Money(1000, 'IDR')
        ));

        $this->expectException(PDOException::class);

        $this->repository->save(new PaymentRefund(
            id: 'rfd-2',
            paymentId: 'pay-1',
            refundNumber: 'RFD-DUPLICATE',
            amount: new Money(1000, 'IDR')
        ));
    }
}
