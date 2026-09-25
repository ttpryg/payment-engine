<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Entities\PaymentRefund;
use Ttpryg\PaymentEngine\Entities\WebhookEvent;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentMethod;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Enums\RefundStatus;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRefundRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoWebhookEventRepository;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;
use Ttpryg\PaymentEngine\ValueObjects\PaymentNumber;

class PdoPaymentRepositoryTest extends TestCase
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

    public function test_save_and_find_payment_with_pdo(): void
    {
        $payment = new Payment(
            id: 'pay-pdo-1',
            paymentNumber: new PaymentNumber('PAY-PDO-001'),
            payable: new PayableReference('order', 'ord-sqlite-101'),
            payer: new PayerReference('user', 'usr-sqlite-50'),
            method: PaymentMethod::QRIS,
            gatewayProvider: 'mock',
            status: PaymentStatus::PENDING,
            amount: new Money(125000, 'IDR'),
            fee: new Money(1500, 'IDR')
        );

        $this->pdoPaymentRepository->save($payment);

        $fetched = $this->pdoPaymentRepository->findById('pay-pdo-1');
        $this->assertNotNull($fetched);
        $this->assertEquals('PAY-PDO-001', $fetched->paymentNumber->value);
        $this->assertEquals('order', $fetched->payable->type);
        $this->assertEquals('ord-sqlite-101', $fetched->payable->id);
        $this->assertEquals(125000, $fetched->amount->amount);
        $this->assertEquals(1500, $fetched->fee->amount);
        $this->assertEquals(126500, $fetched->totalAmount->amount);

        // Attempts
        $paymentAttempt = new PaymentAttempt(
            id: 'att-pdo-1',
            paymentId: 'pay-pdo-1',
            gatewayProvider: 'mock',
            transactionReference: 'tx_qris_999',
            status: AttemptStatus::PENDING,
            amount: new Money(126500, 'IDR')
        );
        $this->pdoPaymentAttemptRepository->save($paymentAttempt);

        $fetchedAttempt = $this->pdoPaymentAttemptRepository->findByTransactionReference('mock', 'tx_qris_999');
        $this->assertNotNull($fetchedAttempt);
        $this->assertEquals('pay-pdo-1', $fetchedAttempt->paymentId);

        // Refund
        $paymentRefund = new PaymentRefund(
            id: 'rfd-pdo-1',
            paymentId: 'pay-pdo-1',
            refundNumber: 'RFD-PDO-001',
            amount: new Money(50000, 'IDR'),
            status: RefundStatus::COMPLETED
        );
        $this->pdoPaymentRefundRepository->save($paymentRefund);

        $fetchedRefund = $this->pdoPaymentRefundRepository->findByRefundNumber('RFD-PDO-001');
        $this->assertNotNull($fetchedRefund);
        $this->assertEquals(50000, $fetchedRefund->amount->amount);

        // History
        $paymentHistory = new PaymentHistory(
            id: 'hist-pdo-1',
            paymentId: 'pay-pdo-1',
            action: PaymentHistoryAction::PAYMENT_CREATED,
            toStatus: PaymentStatus::PENDING,
            actorType: 'system',
            actorId: 'checkout_svc',
            note: 'Order checkout initiated'
        );
        $this->pdoPaymentHistoryRepository->save($paymentHistory);

        $histories = $this->pdoPaymentHistoryRepository->findByPaymentId('pay-pdo-1');
        $this->assertCount(1, $histories);
        $this->assertEquals('system', $histories[0]->actorType);

        // Webhook Event
        $webhookEvent = new WebhookEvent(
            id: 'wh-pdo-1',
            gatewayProvider: 'mock',
            eventId: 'evt_sqlite_01',
            payloadFingerprint: 'abc123hash',
            payload: ['status' => 'settled']
        );
        $this->pdoWebhookEventRepository->save($webhookEvent);

        $fetchedWebhook = $this->pdoWebhookEventRepository->findByFingerprint('mock', 'abc123hash');
        $this->assertNotNull($fetchedWebhook);
        $this->assertEquals('evt_sqlite_01', $fetchedWebhook->eventId);
    }

    public function test_transaction_rollback(): void
    {
        $this->expectException(\RuntimeException::class);

        $this->pdo->beginTransaction();
        try {
            $payment = new Payment(
                id: 'pay-fail-1',
                paymentNumber: new PaymentNumber('PAY-FAIL-1'),
                payable: new PayableReference('order', 'ord-fail'),
                amount: 10000
            );
            $this->pdoPaymentRepository->save($payment);

            throw new \RuntimeException('Forced transaction failure');
        } catch (\Throwable $e) {
            $this->pdo->rollBack();
            throw $e;
        }
    }
}
