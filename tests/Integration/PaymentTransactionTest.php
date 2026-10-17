<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\MockPaymentGateway;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRefundRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoWebhookEventRepository;
use Ttpryg\PaymentEngine\Services\PaymentRefundService;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\Services\WebhookProcessorService;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class PaymentTransactionTest extends TestCase
{
    private PDO $pdo;

    private PdoPaymentRepository $pdoPaymentRepository;

    private PdoPaymentAttemptRepository $pdoPaymentAttemptRepository;

    private PdoPaymentRefundRepository $pdoPaymentRefundRepository;

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
        $this->pdoWebhookEventRepository = new PdoWebhookEventRepository($this->pdo);
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
