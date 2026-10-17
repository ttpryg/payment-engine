<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Contracts\PaymentGatewayInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentCompletedEvent;
use Ttpryg\PaymentEngine\Exceptions\GatewayException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\MidtransGateway;
use Ttpryg\PaymentEngine\Gateways\XenditGateway;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryWebhookEventRepository;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\Services\WebhookProcessorService;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class RealGatewayWebhookTest extends TestCase
{
    private const MIDTRANS_SERVER_KEY = 'SB-Mid-server-testkey';

    private const XENDIT_SECRET_KEY = 'xnd_development_secretkey';

    private const XENDIT_WEBHOOK_TOKEN = 'xendit-callback-token-abc';

    private MemoryPaymentRepository $paymentRepository;

    private MemoryPaymentAttemptRepository $attemptRepository;

    private MemoryPaymentHistoryRepository $historyRepository;

    private ListenerProvider $listenerProvider;

    protected function setUp(): void
    {
        $this->paymentRepository = new MemoryPaymentRepository;
        $this->attemptRepository = new MemoryPaymentAttemptRepository;
        $this->historyRepository = new MemoryPaymentHistoryRepository;
        $this->listenerProvider = new ListenerProvider;
    }

    public function test_midtrans_settlement_webhook_completes_payment_and_is_idempotent(): void
    {
        $eventDispatchedCount = 0;
        $this->listenerProvider->addListener(PaymentCompletedEvent::class, function () use (&$eventDispatchedCount): void {
            $eventDispatchedCount++;
        });

        $processor = $this->processor(new MidtransGateway(self::MIDTRANS_SERVER_KEY));

        $payment = $this->seedPayment('midtrans', 'PAY-MID-1', 'PAY-MID-1');

        $payload = $this->midtransNotification('settlement');

        $processor->process('midtrans', $payload);

        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertTrue($payment->paidAmount->equals(new Money(250000, 'IDR')));
        $this->assertSame(AttemptStatus::SUCCESSFUL, $this->attemptRepository->findByTransactionReference('midtrans', 'PAY-MID-1')?->status);
        $this->assertSame(1, $eventDispatchedCount);

        $historyCount = count($this->historyRepository->findByPaymentId('pay-PAY-MID-1'));

        // Redelivery of the exact same notification must be a no-op.
        $processor->process('midtrans', $payload);

        $this->assertSame(1, $eventDispatchedCount);
        $this->assertCount($historyCount, $this->historyRepository->findByPaymentId('pay-PAY-MID-1'));
    }

    public function test_midtrans_webhook_with_tampered_signature_is_rejected(): void
    {
        $processor = $this->processor(new MidtransGateway(self::MIDTRANS_SERVER_KEY));
        $this->seedPayment('midtrans', 'PAY-MID-1', 'PAY-MID-1');

        $payload = $this->midtransNotification('settlement');
        $payload['gross_amount'] = '1.00';

        $this->expectException(GatewayException::class);

        $processor->process('midtrans', $payload);
    }

    public function test_xendit_paid_webhook_completes_payment(): void
    {
        $processor = $this->processor(new XenditGateway(self::XENDIT_SECRET_KEY, self::XENDIT_WEBHOOK_TOKEN));

        $payment = $this->seedPayment('xendit', 'PAY-XND-1', 'inv-123');

        $processor->process('xendit', [
            'id' => 'inv-123',
            'external_id' => 'PAY-XND-1',
            'status' => 'PAID',
            'paid_amount' => 250000,
            'currency' => 'IDR',
        ], ['x-callback-token' => self::XENDIT_WEBHOOK_TOKEN]);

        $this->assertSame(PaymentStatus::COMPLETED, $payment->status);
        $this->assertSame(AttemptStatus::SUCCESSFUL, $this->attemptRepository->findByTransactionReference('xendit', 'inv-123')?->status);
    }

    public function test_xendit_webhook_with_invalid_token_is_rejected(): void
    {
        $processor = $this->processor(new XenditGateway(self::XENDIT_SECRET_KEY, self::XENDIT_WEBHOOK_TOKEN));
        $this->seedPayment('xendit', 'PAY-XND-1', 'inv-123');

        $this->expectException(GatewayException::class);

        $processor->process('xendit', [
            'id' => 'inv-123',
            'status' => 'PAID',
            'paid_amount' => 250000,
        ], ['x-callback-token' => 'wrong-token']);
    }

    private function processor(PaymentGatewayInterface $gateway): WebhookProcessorService
    {
        $gatewayManager = new GatewayManager;
        $gatewayManager->register($gateway);

        $eventDispatcher = new EventDispatcher($this->listenerProvider);
        $paymentStatusService = new PaymentStatusService($this->paymentRepository, $this->historyRepository, $eventDispatcher);

        return new WebhookProcessorService(
            $gatewayManager,
            new MemoryWebhookEventRepository,
            $this->paymentRepository,
            $this->attemptRepository,
            $this->historyRepository,
            $paymentStatusService
        );
    }

    private function seedPayment(string $provider, string $paymentNumber, string $transactionReference): Payment
    {
        $payment = new Payment(
            id: 'pay-'.$paymentNumber,
            paymentNumber: $paymentNumber,
            payable: new PayableReference('order', 'ord-'.$paymentNumber),
            gatewayProvider: $provider,
            status: PaymentStatus::PENDING,
            amount: 250000
        );
        $this->paymentRepository->save($payment);

        $this->attemptRepository->save(new PaymentAttempt(
            id: 'att-'.$paymentNumber,
            paymentId: $payment->id,
            gatewayProvider: $provider,
            transactionReference: $transactionReference,
            status: AttemptStatus::PENDING,
            amount: new Money(250000, 'IDR')
        ));

        return $payment;
    }

    /**
     * @return array<string, mixed>
     */
    private function midtransNotification(string $transactionStatus): array
    {
        $payload = [
            'order_id' => 'PAY-MID-1',
            'status_code' => '200',
            'gross_amount' => '250000.00',
            'currency' => 'IDR',
            'transaction_status' => $transactionStatus,
            'fraud_status' => 'accept',
            'transaction_id' => 'tx-mid-1',
        ];

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::MIDTRANS_SERVER_KEY
        );

        return $payload;
    }
}
