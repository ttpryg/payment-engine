<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Application\Service\PaymentRefundService;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Event\PaymentPartiallyRefundedEvent;
use Ttpryg\PaymentEngine\Domain\Event\PaymentRefundedEvent;
use Ttpryg\PaymentEngine\Domain\Exception\RefundAmountExceededException;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\MockPaymentGateway;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentRefundRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Tests\Support\RecordingEventDispatcher;

class PaymentRefundServiceTest extends TestCase
{
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentRefundRepository $memoryPaymentRefundRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private RecordingEventDispatcher $eventDispatcher;

    private PaymentRefundService $paymentRefundService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentRefundRepository = new MemoryPaymentRefundRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $this->eventDispatcher = new RecordingEventDispatcher;

        $this->paymentRefundService = new PaymentRefundService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentRefundRepository,
            $this->memoryPaymentHistoryRepository,
            $gatewayManager,
            $this->eventDispatcher
        );
    }

    public function test_partial_and_full_refund_lifecycle(): void
    {
        $partialEventFired = false;
        $fullEventFired = false;

        $this->eventDispatcher->addListener(PaymentPartiallyRefundedEvent::class, function () use (&$partialEventFired): void {
            $partialEventFired = true;
        });

        $this->eventDispatcher->addListener(PaymentRefundedEvent::class, function () use (&$fullEventFired): void {
            $fullEventFired = true;
        });

        $payment = new Payment(
            id: 'pay-refund-1',
            paymentNumber: 'PAY-RF-1',
            payable: new PayableReference('order', 'ord-99'),
            gatewayProvider: 'mock',
            status: PaymentStatus::COMPLETED,
            amount: 100000,
            paidAmount: new Money(100000, 'IDR')
        );
        $this->memoryPaymentRepository->save($payment);

        // 1. Partial refund of 40,000
        $paymentRefund = $this->paymentRefundService->issueRefund('pay-refund-1', new Money(40000, 'IDR'), reason: 'Damaged item');
        $this->assertEquals(40000, $paymentRefund->amount->amount);
        $this->assertEquals(PaymentStatus::PARTIALLY_REFUNDED, $payment->status);
        $this->assertEquals(40000, $payment->refundedAmount->amount);
        $this->assertEquals(60000, $payment->getRefundableAmount()->amount);
        $this->assertTrue($partialEventFired);

        // 2. Full refund of remaining 60,000
        $this->paymentRefundService->issueRefund('pay-refund-1', new Money(60000, 'IDR'), reason: 'Return balance');
        $this->assertEquals(PaymentStatus::REFUNDED, $payment->status);
        $this->assertEquals(100000, $payment->refundedAmount->amount);
        $this->assertEquals(0, $payment->getRefundableAmount()->amount);
        $this->assertTrue($fullEventFired);

        $allRefunds = $this->memoryPaymentRefundRepository->findByPaymentId('pay-refund-1');
        $this->assertCount(2, $allRefunds);
    }

    public function test_refund_exceeding_paid_amount_throws_exception(): void
    {
        $payment = new Payment(
            id: 'pay-refund-2',
            paymentNumber: 'PAY-RF-2',
            payable: new PayableReference('order', 'ord-100'),
            gatewayProvider: 'mock',
            status: PaymentStatus::COMPLETED,
            amount: 50000,
            paidAmount: new Money(50000, 'IDR')
        );
        $this->memoryPaymentRepository->save($payment);

        $this->expectException(RefundAmountExceededException::class);
        $this->paymentRefundService->issueRefund('pay-refund-2', new Money(60000, 'IDR'));
    }
}
