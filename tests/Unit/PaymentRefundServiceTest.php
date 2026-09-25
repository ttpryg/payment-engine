<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentPartiallyRefundedEvent;
use Ttpryg\PaymentEngine\Events\PaymentRefundedEvent;
use Ttpryg\PaymentEngine\Exceptions\RefundAmountExceededException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\MockPaymentGateway;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRefundRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Services\PaymentRefundService;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class PaymentRefundServiceTest extends TestCase
{
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentRefundRepository $memoryPaymentRefundRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private ListenerProvider $listenerProvider;

    private PaymentRefundService $paymentRefundService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentRefundRepository = new MemoryPaymentRefundRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $this->listenerProvider = new ListenerProvider;
        $eventDispatcher = new EventDispatcher($this->listenerProvider);

        $this->paymentRefundService = new PaymentRefundService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentRefundRepository,
            $this->memoryPaymentHistoryRepository,
            $gatewayManager,
            $eventDispatcher
        );
    }

    public function test_partial_and_full_refund_lifecycle(): void
    {
        $partialEventFired = false;
        $fullEventFired = false;

        $this->listenerProvider->addListener(PaymentPartiallyRefundedEvent::class, function () use (&$partialEventFired): void {
            $partialEventFired = true;
        });

        $this->listenerProvider->addListener(PaymentRefundedEvent::class, function () use (&$fullEventFired): void {
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
