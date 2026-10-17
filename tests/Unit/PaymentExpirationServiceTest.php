<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentExpiredEvent;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Services\PaymentExpirationService;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class PaymentExpirationServiceTest extends TestCase
{
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private PaymentExpirationService $paymentExpirationService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;

        $listenerProvider = new ListenerProvider;
        $eventDispatcher = new EventDispatcher($listenerProvider);

        $paymentStatusService = new PaymentStatusService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentHistoryRepository,
            $eventDispatcher
        );

        $this->paymentExpirationService = new PaymentExpirationService(
            $this->memoryPaymentRepository,
            $paymentStatusService
        );
    }

    public function test_expire_overdue_transitions_only_overdue_pending_payments(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $overdue = new Payment(
            id: 'pay-exp-1',
            paymentNumber: 'PAY-EXP-1',
            payable: new PayableReference('order', 'ord-exp-1'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('-1 hour')
        );

        $notYetDue = new Payment(
            id: 'pay-exp-2',
            paymentNumber: 'PAY-EXP-2',
            payable: new PayableReference('order', 'ord-exp-2'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('+1 hour')
        );

        $alreadyCompleted = new Payment(
            id: 'pay-exp-3',
            paymentNumber: 'PAY-EXP-3',
            payable: new PayableReference('order', 'ord-exp-3'),
            status: PaymentStatus::COMPLETED,
            amount: 50000,
            paidAmount: new Money(50000, 'IDR'),
            expiresAt: $now->modify('-2 hours')
        );

        $noExpiry = new Payment(
            id: 'pay-exp-4',
            paymentNumber: 'PAY-EXP-4',
            payable: new PayableReference('order', 'ord-exp-4'),
            status: PaymentStatus::PENDING,
            amount: 50000
        );

        foreach ([$overdue, $notYetDue, $alreadyCompleted, $noExpiry] as $payment) {
            $this->memoryPaymentRepository->save($payment);
        }

        $this->assertSame(1, $this->paymentExpirationService->countExpirable($now));

        $expired = $this->paymentExpirationService->expireOverdue($now);

        $this->assertCount(1, $expired);
        $this->assertSame('pay-exp-1', $expired[0]->id);
        $this->assertEquals(PaymentStatus::EXPIRED, $overdue->status);
        $this->assertEquals(PaymentStatus::PENDING, $notYetDue->status);
        $this->assertEquals(PaymentStatus::COMPLETED, $alreadyCompleted->status);
        $this->assertEquals(PaymentStatus::PENDING, $noExpiry->status);

        $histories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-exp-1');
        $this->assertCount(1, $histories);
        $this->assertEquals('system', $histories[0]->actorType);
    }

    public function test_expire_overdue_dispatches_expired_event(): void
    {
        $expiredEventFired = false;

        $listenerProvider = new ListenerProvider;
        $listenerProvider->addListener(PaymentExpiredEvent::class, function () use (&$expiredEventFired): void {
            $expiredEventFired = true;
        });
        $eventDispatcher = new EventDispatcher($listenerProvider);

        $paymentStatusService = new PaymentStatusService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentHistoryRepository,
            $eventDispatcher
        );

        $paymentExpirationService = new PaymentExpirationService(
            $this->memoryPaymentRepository,
            $paymentStatusService
        );

        $overdue = new Payment(
            id: 'pay-exp-5',
            paymentNumber: 'PAY-EXP-5',
            payable: new PayableReference('order', 'ord-exp-5'),
            status: PaymentStatus::PENDING,
            amount: 75000,
            expiresAt: new DateTimeImmutable('2026-10-17 10:00:00')
        );
        $this->memoryPaymentRepository->save($overdue);

        $paymentExpirationService->expireOverdue(new DateTimeImmutable('2026-10-17 12:00:00'));

        $this->assertTrue($expiredEventFired);
        $this->assertEquals(PaymentStatus::EXPIRED, $overdue->status);
    }
}
