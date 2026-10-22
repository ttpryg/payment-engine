<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use DateTimeImmutable;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Application\DTO\GatewayStatusResponse;
use Ttpryg\PaymentEngine\Application\Service\GatewayReconciliationService;
use Ttpryg\PaymentEngine\Application\Service\PaymentStatusService;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentAttempt;
use Ttpryg\PaymentEngine\Domain\Enum\AttemptStatus;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\MockPaymentGateway;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentRepository;

class GatewayReconciliationServiceTest extends TestCase
{
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentAttemptRepository $memoryPaymentAttemptRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private GatewayManager $gatewayManager;

    private PaymentStatusService $paymentStatusService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentAttemptRepository = new MemoryPaymentAttemptRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;

        $this->gatewayManager = new GatewayManager;
        $this->gatewayManager->register(new MockPaymentGateway('mock'));

        $listenerProvider = new ListenerProvider;
        $eventDispatcher = new EventDispatcher($listenerProvider);

        $this->paymentStatusService = new PaymentStatusService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentHistoryRepository,
            $eventDispatcher
        );
    }

    public function test_reconcile_syncs_completed_status_from_gateway(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $payment = new Payment(
            id: 'pay-rec-1',
            paymentNumber: 'PAY-REC-1',
            payable: new PayableReference('order', 'ord-rec-1'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 100000,
            expiresAt: $now->modify('+2 minutes')
        );
        $this->memoryPaymentRepository->save($payment);

        $attempt = new PaymentAttempt(
            id: 'att-rec-1',
            paymentId: 'pay-rec-1',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_rec_1',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR')
        );
        $this->memoryPaymentAttemptRepository->save($attempt);

        $reconciled = $this->service()->reconcile($now->modify('+5 minutes'));

        $this->assertCount(1, $reconciled);
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(AttemptStatus::SUCCESSFUL, $attempt->status);
        $this->assertCount(1, $this->memoryPaymentHistoryRepository->findByPaymentId('pay-rec-1'));
    }

    public function test_reconcile_syncs_failed_status_from_gateway(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $payment = new Payment(
            id: 'pay-rec-2',
            paymentNumber: 'PAY-REC-2',
            payable: new PayableReference('order', 'ord-rec-2'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 100000,
            expiresAt: $now->modify('-1 minute')
        );
        $this->memoryPaymentRepository->save($payment);

        $attempt = new PaymentAttempt(
            id: 'att-rec-2',
            paymentId: 'pay-rec-2',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_rec_2',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR')
        );
        $this->memoryPaymentAttemptRepository->save($attempt);

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new class('mock') extends MockPaymentGateway
        {
            public function checkStatus(string $transactionReference): GatewayStatusResponse
            {
                return new GatewayStatusResponse(
                    mappedStatus: PaymentStatus::FAILED,
                    transactionReference: $transactionReference,
                    rawResponse: ['status' => 'failed']
                );
            }
        });

        $reconciled = $this->service($gatewayManager)->reconcile($now);

        $this->assertCount(1, $reconciled);
        $this->assertEquals(PaymentStatus::FAILED, $payment->status);
        $this->assertEquals(AttemptStatus::FAILED, $attempt->status);
    }

    public function test_reconcile_ignores_payments_not_approaching_expiration(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $payment = new Payment(
            id: 'pay-rec-3',
            paymentNumber: 'PAY-REC-3',
            payable: new PayableReference('order', 'ord-rec-3'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 100000,
            expiresAt: $now->modify('+1 day')
        );
        $this->memoryPaymentRepository->save($payment);

        $this->memoryPaymentAttemptRepository->save(new PaymentAttempt(
            id: 'att-rec-3',
            paymentId: 'pay-rec-3',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_rec_3',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR')
        ));

        $reconciled = $this->service()->reconcile($now);

        $this->assertCount(0, $reconciled);
        $this->assertEquals(PaymentStatus::PENDING, $payment->status);
    }

    public function test_reconcile_swallows_gateway_failures(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $payment = new Payment(
            id: 'pay-rec-4',
            paymentNumber: 'PAY-REC-4',
            payable: new PayableReference('order', 'ord-rec-4'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 100000,
            expiresAt: $now->modify('-1 minute')
        );
        $this->memoryPaymentRepository->save($payment);

        $this->memoryPaymentAttemptRepository->save(new PaymentAttempt(
            id: 'att-rec-4',
            paymentId: 'pay-rec-4',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_rec_4',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR')
        ));

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new class('mock') extends MockPaymentGateway
        {
            public function checkStatus(string $transactionReference): GatewayStatusResponse
            {
                throw new RuntimeException('Gateway timeout');
            }
        });

        $reconciled = $this->service($gatewayManager)->reconcile($now);

        $this->assertCount(0, $reconciled);
        $this->assertEquals(PaymentStatus::PENDING, $payment->status);
        $this->assertCount(0, $this->memoryPaymentHistoryRepository->findByPaymentId('pay-rec-4'));
    }

    public function test_reconcile_skips_payments_without_referenced_attempt(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $payment = new Payment(
            id: 'pay-rec-5',
            paymentNumber: 'PAY-REC-5',
            payable: new PayableReference('order', 'ord-rec-5'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 100000,
            expiresAt: $now->modify('-1 minute')
        );
        $this->memoryPaymentRepository->save($payment);

        $reconciled = $this->service()->reconcile($now);

        $this->assertCount(0, $reconciled);
        $this->assertEquals(PaymentStatus::PENDING, $payment->status);
    }

    private function service(?GatewayManager $gatewayManager = null): GatewayReconciliationService
    {
        return new GatewayReconciliationService(
            $gatewayManager ?? $this->gatewayManager,
            $this->memoryPaymentRepository,
            $this->memoryPaymentAttemptRepository,
            $this->paymentStatusService
        );
    }
}
