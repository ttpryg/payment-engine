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
    private MemoryPaymentRepository $paymentRepo;

    private MemoryPaymentHistoryRepository $historyRepo;

    private ListenerProvider $listenerProvider;

    private PaymentStatusService $statusService;

    protected function setUp(): void
    {
        $this->paymentRepo = new MemoryPaymentRepository;
        $this->historyRepo = new MemoryPaymentHistoryRepository;
        $this->listenerProvider = new ListenerProvider;
        $dispatcher = new EventDispatcher($this->listenerProvider);

        $this->statusService = new PaymentStatusService(
            $this->paymentRepo,
            $this->historyRepo,
            $dispatcher
        );
    }

    public function test_valid_status_transitions_and_events(): void
    {
        $completedDispatched = false;
        $this->listenerProvider->addListener(PaymentCompletedEvent::class, function (PaymentCompletedEvent $event) use (&$completedDispatched) {
            $completedDispatched = true;
            $this->assertEquals('pay-test-1', $event->payment->id);
        });

        $payment = new Payment(
            id: 'pay-test-1',
            paymentNumber: 'PAY-001',
            payable: new PayableReference('order', 'ord-100'),
            amount: 150000
        );
        $this->paymentRepo->save($payment);

        $this->statusService->complete('pay-test-1');
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(150000, $payment->paidAmount->amount);
        $this->assertNotNull($payment->paidAt);
        $this->assertTrue($completedDispatched);

        $histories = $this->historyRepo->findByPaymentId('pay-test-1');
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
        $this->paymentRepo->save($payment);

        $this->expectException(InvalidStatusTransitionException::class);
        $this->statusService->complete('pay-test-2');
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
        $this->paymentRepo->save($payment);

        // Calling complete again on already completed payment
        $this->statusService->complete('pay-test-3');
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);

        // No new history appended
        $histories = $this->historyRepo->findByPaymentId('pay-test-3');
        $this->assertCount(0, $histories);
    }

    public function test_cancel_payment_dispatches_event(): void
    {
        $cancelledDispatched = false;
        $this->listenerProvider->addListener(PaymentCancelledEvent::class, function (PaymentCancelledEvent $event) use (&$cancelledDispatched) {
            $cancelledDispatched = true;
            $this->assertEquals('Customer request', $event->reason);
        });

        $payment = new Payment(
            id: 'pay-test-4',
            paymentNumber: 'PAY-004',
            payable: new PayableReference('order', 'ord-400'),
            amount: 200000
        );
        $this->paymentRepo->save($payment);

        $this->statusService->cancel('pay-test-4', reason: 'Customer request');
        $this->assertEquals(PaymentStatus::CANCELLED, $payment->status);
        $this->assertTrue($cancelledDispatched);
    }
}
