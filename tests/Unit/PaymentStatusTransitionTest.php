<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentCancelledEvent;
use Ttpryg\PaymentEngine\Events\PaymentCompletedEvent;
use Ttpryg\PaymentEngine\Exceptions\InvalidStatusTransitionException;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class PaymentStatusTransitionTest extends TestCase
{
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private ListenerProvider $listenerProvider;

    private PaymentStatusService $paymentStatusService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;
        $this->listenerProvider = new ListenerProvider;
        $eventDispatcher = new EventDispatcher($this->listenerProvider);

        $this->paymentStatusService = new PaymentStatusService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentHistoryRepository,
            $eventDispatcher
        );
    }

    public function test_valid_status_transitions_and_events(): void
    {
        $completedDispatched = false;
        $this->listenerProvider->addListener(PaymentCompletedEvent::class, function (PaymentCompletedEvent $paymentCompletedEvent) use (&$completedDispatched): void {
            $completedDispatched = true;
            $this->assertEquals('pay-test-1', $paymentCompletedEvent->payment->id);
        });

        $payment = new Payment(
            id: 'pay-test-1',
            paymentNumber: 'PAY-001',
            payable: new PayableReference('order', 'ord-100'),
            amount: 150000
        );
        $this->memoryPaymentRepository->save($payment);

        $this->paymentStatusService->complete('pay-test-1');
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(150000, $payment->paidAmount->amount);
        $this->assertNotNull($payment->paidAt);
        $this->assertTrue($completedDispatched);

        $histories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-test-1');
        $this->assertCount(1, $histories);
    }

    public function test_invalid_transition_throws_exception(): void
    {
        $payment = new Payment(
            id: 'pay-test-2',
            paymentNumber: 'PAY-002',
            payable: new PayableReference('order', 'ord-200'),
            status: PaymentStatus::FAILED,
            amount: 50000
        );
        $this->memoryPaymentRepository->save($payment);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->paymentStatusService->complete('pay-test-2');
    }

    public function test_idempotent_status_transition(): void
    {
        $payment = new Payment(
            id: 'pay-test-3',
            paymentNumber: 'PAY-003',
            payable: new PayableReference('order', 'ord-300'),
            status: PaymentStatus::COMPLETED,
            amount: 75000
        );
        $this->memoryPaymentRepository->save($payment);

        // Calling complete again on already completed payment
        $this->paymentStatusService->complete('pay-test-3');
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);

        // No new history appended
        $histories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-test-3');
        $this->assertCount(0, $histories);
    }

    public function test_cancel_payment_dispatches_event(): void
    {
        $cancelledDispatched = false;
        $this->listenerProvider->addListener(PaymentCancelledEvent::class, function (PaymentCancelledEvent $paymentCancelledEvent) use (&$cancelledDispatched): void {
            $cancelledDispatched = true;
            $this->assertEquals('Customer request', $paymentCancelledEvent->reason);
        });

        $payment = new Payment(
            id: 'pay-test-4',
            paymentNumber: 'PAY-004',
            payable: new PayableReference('order', 'ord-400'),
            amount: 200000
        );
        $this->memoryPaymentRepository->save($payment);

        $this->paymentStatusService->cancel('pay-test-4', reason: 'Customer request');
        $this->assertEquals(PaymentStatus::CANCELLED, $payment->status);
        $this->assertTrue($cancelledDispatched);
    }
}
