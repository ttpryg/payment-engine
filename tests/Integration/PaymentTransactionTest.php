<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ttpryg\PaymentEngine\Application\Service\GatewayReconciliationService;
use Ttpryg\PaymentEngine\Application\Service\PaymentRefundService;
use Ttpryg\PaymentEngine\Application\Service\PaymentStatusService;
use Ttpryg\PaymentEngine\Application\Service\WebhookProcessorService;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentAttempt;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;
use Ttpryg\PaymentEngine\Domain\Enum\AttemptStatus;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\MockPaymentGateway;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentRefundRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoPaymentRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoWebhookEventRepository;

class PaymentTransactionTest extends TestCase
{
    private PDO $pdo;

    private PdoPaymentRepository $pdoPaymentRepository;

    private PdoPaymentAttemptRepository $pdoPaymentAttemptRepository;

    private PdoPaymentRefundRepository $pdoPaymentRefundRepository;

    private PdoPaymentHistoryRepository $pdoPaymentHistoryRepository;

    private PdoWebhookEventRepository $pdoWebhookEventRepository;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $schema = file_get_contents(__DIR__.'/../../database/schema.sql');
        $this->pdo->exec($schema);

        $this->pdoPaymentRepository = new PdoPaymentRepository($this->pdo);
        $this->pdoPaymentAttemptRepository = new PdoPaymentAttemptRepository($this->pdo);
        $this->pdoPaymentRefundRepository = new PdoPaymentRefundRepository($this->pdo);
        $this->pdoPaymentHistoryRepository = new PdoPaymentHistoryRepository($this->pdo);
        $this->pdoWebhookEventRepository = new PdoWebhookEventRepository($this->pdo);
    }

    public function test_find_by_id_for_update_returns_payment_within_transaction(): void
    {
        $payment = new Payment(
            id: 'pay-lock-1',
            paymentNumber: 'PAY-LOCK-1',
            payable: new PayableReference('order', 'ord-lock-1'),
            status: PaymentStatus::PENDING,
            amount: 100000
        );
        $this->pdoPaymentRepository->save($payment);

        $this->pdo->beginTransaction();
        $locked = $this->pdoPaymentRepository->findByIdForUpdate('pay-lock-1');
        $this->pdo->rollBack();

        $this->assertNotNull($locked);
        $this->assertEquals('pay-lock-1', $locked->id);
        $this->assertNull($this->pdoPaymentRepository->findByIdForUpdate('pay-lock-missing'));
    }

    public function test_find_pending_expired_returns_only_overdue_pending_payments(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-exp-pdo-1',
            paymentNumber: 'PAY-EXP-PDO-1',
            payable: new PayableReference('order', 'ord-exp-pdo-1'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('-1 hour')
        ));

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-exp-pdo-2',
            paymentNumber: 'PAY-EXP-PDO-2',
            payable: new PayableReference('order', 'ord-exp-pdo-2'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('+1 hour')
        ));

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-exp-pdo-3',
            paymentNumber: 'PAY-EXP-PDO-3',
            payable: new PayableReference('order', 'ord-exp-pdo-3'),
            status: PaymentStatus::PENDING,
            amount: 50000
        ));

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-exp-pdo-4',
            paymentNumber: 'PAY-EXP-PDO-4',
            payable: new PayableReference('order', 'ord-exp-pdo-4'),
            status: PaymentStatus::COMPLETED,
            amount: 50000,
            paidAmount: new Money(50000, 'IDR'),
            expiresAt: $now->modify('-2 hours')
        ));

        $result = $this->pdoPaymentRepository->findPendingExpired($now);

        $this->assertCount(1, $result);
        $this->assertSame('pay-exp-pdo-1', $result[0]->id);
    }

    public function test_find_pending_expiring_before_includes_near_and_past_expiry(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');
        $cutoff = $now->modify('+5 minutes');

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-near',
            paymentNumber: 'PAY-NEAR',
            payable: new PayableReference('order', 'ord-near'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('+2 minutes')
        ));

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-past',
            paymentNumber: 'PAY-PAST',
            payable: new PayableReference('order', 'ord-past'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('-10 minutes')
        ));

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-far',
            paymentNumber: 'PAY-FAR',
            payable: new PayableReference('order', 'ord-far'),
            status: PaymentStatus::PENDING,
            amount: 50000,
            expiresAt: $now->modify('+1 day')
        ));

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-done',
            paymentNumber: 'PAY-DONE',
            payable: new PayableReference('order', 'ord-done'),
            status: PaymentStatus::COMPLETED,
            amount: 50000,
            paidAmount: new Money(50000, 'IDR'),
            expiresAt: $now->modify('+1 minute')
        ));

        $result = $this->pdoPaymentRepository->findPendingExpiringBefore($cutoff);
        $ids = array_map(static fn (Payment $payment): string => $payment->id, $result);

        $this->assertCount(2, $result);
        $this->assertContains('pay-near', $ids);
        $this->assertContains('pay-past', $ids);
    }

    public function test_gateway_reconciliation_with_pdo_syncs_status(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-rec-pdo-1',
            paymentNumber: 'PAY-REC-PDO-1',
            payable: new PayableReference('order', 'ord-rec-pdo-1'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 100000,
            expiresAt: $now->modify('+1 minute')
        ));

        $this->pdoPaymentAttemptRepository->save(new PaymentAttempt(
            id: 'att-rec-pdo-1',
            paymentId: 'pay-rec-pdo-1',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_rec_pdo_1',
            status: AttemptStatus::PENDING,
            amount: new Money(100000, 'IDR')
        ));

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $paymentStatusService = new PaymentStatusService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentHistoryRepository,
            pdo: $this->pdo
        );

        $reconciliationService = new GatewayReconciliationService(
            $gatewayManager,
            $this->pdoPaymentRepository,
            $this->pdoPaymentAttemptRepository,
            $paymentStatusService,
            $this->pdo
        );

        $reconciled = $reconciliationService->reconcile($now->modify('+5 minutes'));

        $this->assertCount(1, $reconciled);

        $fresh = $this->pdoPaymentRepository->findById('pay-rec-pdo-1');
        $this->assertNotNull($fresh);
        $this->assertEquals(PaymentStatus::COMPLETED, $fresh->status);

        $attempt = $this->pdoPaymentAttemptRepository->findByTransactionReference('mock', 'mock_tx_rec_pdo_1');
        $this->assertNotNull($attempt);
        $this->assertEquals(AttemptStatus::SUCCESSFUL, $attempt->status);
    }

    public function test_status_change_persists_with_pdo_transaction(): void
    {
        $payment = new Payment(
            id: 'pay-tx-ok-status',
            paymentNumber: 'PAY-TX-OK-STATUS',
            payable: new PayableReference('order', 'ord-tx-ok-status'),
            status: PaymentStatus::PENDING,
            amount: 100000
        );
        $this->pdoPaymentRepository->save($payment);

        $paymentStatusService = new PaymentStatusService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentHistoryRepository,
            pdo: $this->pdo
        );

        $updated = $paymentStatusService->complete('pay-tx-ok-status');

        $this->assertEquals(PaymentStatus::COMPLETED, $updated->status);

        $fresh = $this->pdoPaymentRepository->findById('pay-tx-ok-status');
        $this->assertNotNull($fresh);
        $this->assertEquals(PaymentStatus::COMPLETED, $fresh->status);
        $this->assertEquals(100000, $fresh->paidAmount->amount);
        $this->assertCount(1, $this->pdoPaymentHistoryRepository->findByPaymentId('pay-tx-ok-status'));
    }

    public function test_refund_persists_with_pdo_transaction(): void
    {
        $payment = new Payment(
            id: 'pay-tx-ok-refund',
            paymentNumber: 'PAY-TX-OK-REFUND',
            payable: new PayableReference('order', 'ord-tx-ok-refund'),
            gatewayProvider: 'mock',
            status: PaymentStatus::COMPLETED,
            amount: 100000,
            paidAmount: new Money(100000, 'IDR')
        );
        $this->pdoPaymentRepository->save($payment);

        $paymentRefundService = new PaymentRefundService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentRefundRepository,
            $this->pdoPaymentHistoryRepository,
            null,
            null,
            $this->pdo
        );

        $refund = $paymentRefundService->issueRefund('pay-tx-ok-refund', new Money(40000, 'IDR'), reason: 'Partial return');

        $this->assertEquals(40000, $refund->amount->amount);

        $fresh = $this->pdoPaymentRepository->findById('pay-tx-ok-refund');
        $this->assertNotNull($fresh);
        $this->assertEquals(PaymentStatus::PARTIALLY_REFUNDED, $fresh->status);
        $this->assertEquals(40000, $fresh->refundedAmount->amount);
        $this->assertCount(1, $this->pdoPaymentRefundRepository->findByPaymentId('pay-tx-ok-refund'));
    }

    public function test_status_change_rolls_back_when_history_persistence_fails(): void
    {
        $payment = new Payment(
            id: 'pay-tx-status-1',
            paymentNumber: 'PAY-TX-STATUS-1',
            payable: new PayableReference('order', 'ord-tx-1'),
            status: PaymentStatus::PENDING,
            amount: 100000
        );
        $this->pdoPaymentRepository->save($payment);

        $paymentStatusService = new PaymentStatusService(
            $this->pdoPaymentRepository,
            $this->failingHistoryRepository(),
            null,
            $this->pdo
        );

        try {
            $paymentStatusService->complete('pay-tx-status-1');
            $this->fail('Expected history persistence failure to be thrown');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $this->pdoPaymentRepository->findById('pay-tx-status-1');
        $this->assertNotNull($fresh);
        $this->assertEquals(PaymentStatus::PENDING, $fresh->status);
        $this->assertEquals(0, $fresh->paidAmount->amount);
    }

    public function test_refund_rolls_back_when_history_persistence_fails(): void
    {
        $payment = new Payment(
            id: 'pay-tx-refund-1',
            paymentNumber: 'PAY-TX-REFUND-1',
            payable: new PayableReference('order', 'ord-tx-2'),
            gatewayProvider: 'mock',
            status: PaymentStatus::COMPLETED,
            amount: 100000,
            paidAmount: new Money(100000, 'IDR')
        );
        $this->pdoPaymentRepository->save($payment);

        $paymentRefundService = new PaymentRefundService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentRefundRepository,
            $this->failingHistoryRepository(),
            null,
            null,
            $this->pdo
        );

        try {
            $paymentRefundService->issueRefund('pay-tx-refund-1', new Money(40000, 'IDR'));
            $this->fail('Expected history persistence failure to be thrown');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $this->pdoPaymentRepository->findById('pay-tx-refund-1');
        $this->assertNotNull($fresh);
        $this->assertEquals(PaymentStatus::COMPLETED, $fresh->status);
        $this->assertEquals(0, $fresh->refundedAmount->amount);
        $this->assertCount(0, $this->pdoPaymentRefundRepository->findByPaymentId('pay-tx-refund-1'));
    }

    public function test_webhook_processing_rolls_back_all_writes_on_failure(): void
    {
        $payment = new Payment(
            id: 'pay-tx-webhook-1',
            paymentNumber: 'PAY-TX-WEBHOOK-1',
            payable: new PayableReference('order', 'ord-tx-3'),
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: 300000
        );
        $this->pdoPaymentRepository->save($payment);

        $attempt = new PaymentAttempt(
            id: 'att-tx-webhook-1',
            paymentId: 'pay-tx-webhook-1',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_tx_1',
            status: AttemptStatus::PENDING,
            amount: new Money(300000, 'IDR')
        );
        $this->pdoPaymentAttemptRepository->save($attempt);

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $failingHistoryRepository = $this->failingHistoryRepository();

        $paymentStatusService = new PaymentStatusService(
            $this->pdoPaymentRepository,
            $failingHistoryRepository,
            null,
            $this->pdo
        );

        $webhookProcessorService = new WebhookProcessorService(
            $gatewayManager,
            $this->pdoWebhookEventRepository,
            $this->pdoPaymentRepository,
            $this->pdoPaymentAttemptRepository,
            $failingHistoryRepository,
            $paymentStatusService,
            $this->pdo
        );

        $payload = [
            'event_id' => 'evt_tx_webhook_1',
            'transaction_reference' => 'mock_tx_tx_1',
            'status' => 'completed',
            'paid_amount' => 300000,
        ];

        try {
            $webhookProcessorService->process('mock', $payload);
            $this->fail('Expected history persistence failure to be thrown');
        } catch (RuntimeException) {
            // expected
        }

        $fresh = $this->pdoPaymentRepository->findById('pay-tx-webhook-1');
        $this->assertNotNull($fresh);
        $this->assertEquals(PaymentStatus::PENDING, $fresh->status);

        // The webhook event row recorded before the failure must be rolled back too.
        $this->assertNull($this->pdoWebhookEventRepository->findByEventId('mock', 'evt_tx_webhook_1'));

        $attempt = $this->pdoPaymentAttemptRepository->findByTransactionReference('mock', 'mock_tx_tx_1');
        $this->assertNotNull($attempt);
        $this->assertEquals(AttemptStatus::PENDING, $attempt->status);
    }

    private function failingHistoryRepository(): PaymentHistoryRepositoryInterface
    {
        return new class implements PaymentHistoryRepositoryInterface
        {
            public function save(PaymentHistory $paymentHistory): void
            {
                throw new RuntimeException('Forced history persistence failure');
            }

            public function findByPaymentId(string $paymentId): array
            {
                return [];
            }
        };
    }
}
