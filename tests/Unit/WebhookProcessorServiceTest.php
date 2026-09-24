<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentCompletedEvent;
use Ttpryg\PaymentEngine\Exceptions\GatewayException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\MockPaymentGateway;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryWebhookEventRepository;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\Services\WebhookProcessorService;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class WebhookProcessorServiceTest extends TestCase
{
    private MemoryPaymentRepository $paymentRepo;

    private MemoryPaymentAttemptRepository $attemptRepo;

    private MemoryPaymentHistoryRepository $historyRepo;

    private MemoryWebhookEventRepository $webhookRepo;

    private ListenerProvider $listenerProvider;

    private WebhookProcessorService $webhookProcessor;

    protected function setUp(): void
    {
        $this->paymentRepo = new MemoryPaymentRepository;
        $this->attemptRepo = new MemoryPaymentAttemptRepository;
        $this->historyRepo = new MemoryPaymentHistoryRepository;
        $this->webhookRepo = new MemoryWebhookEventRepository;

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $this->listenerProvider = new ListenerProvider;
        $dispatcher = new EventDispatcher($this->listenerProvider);

        $statusService = new PaymentStatusService($this->paymentRepo, $this->historyRepo, $dispatcher);

        $this->webhookProcessor = new WebhookProcessorService(
            $gatewayManager,
            $this->webhookRepo,
            $this->paymentRepo,
            $this->attemptRepo,
            $this->historyRepo,
            $statusService
        );
    }

    public function test_process_webhook_and_deduplicate_webhook_execution(): void
    {
        $eventDispatchedCount = 0;
        $this->listenerProvider->addListener(PaymentCompletedEvent::class, function () use (&$eventDispatchedCount) {
            $eventDispatchedCount++;
        });

        // 1. Prepare payment & attempt
        $payment = new Payment(
            id: 'pay-wh-1',
            paymentNumber: 'PAY-WH-1',
            payable: new PayableReference('order', 'ord-999'),
            gatewayProvider: 'mock',
            amount: 300000
        );
        $this->paymentRepo->save($payment);

        $attempt = new PaymentAttempt(
            id: 'att-wh-1',
            paymentId: 'pay-wh-1',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_12345',
            status: AttemptStatus::PENDING,
            amount: new Money(300000, 'IDR')
        );
        $this->attemptRepo->save($attempt);

        $payload = [
            'event_id' => 'evt_99999',
            'transaction_reference' => 'mock_tx_12345',
            'status' => 'completed',
            'paid_amount' => 300000,
        ];

        // 2. First Webhook Ingestion
        $res1 = $this->webhookProcessor->process('mock', $payload);
        $this->assertTrue($res1->isValid);
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(1, $eventDispatchedCount);

        $histories = $this->historyRepo->findByPaymentId('pay-wh-1');
        $initialHistoryCount = count($histories);
        $this->assertGreaterThan(0, $initialHistoryCount);

        // 3. Second Webhook Ingestion (Duplicate delivery from gateway)
        $res2 = $this->webhookProcessor->process('mock', $payload);
        $this->assertTrue($res2->isValid);

        // Assert: NO duplicate domain events dispatched!
        $this->assertEquals(1, $eventDispatchedCount);

        // Assert: NO duplicate history records appended!
        $newHistories = $this->historyRepo->findByPaymentId('pay-wh-1');
        $this->assertCount($initialHistoryCount, $newHistories);
    }

    public function test_invalid_signature_throws_gateway_exception(): void
    {
        $this->expectException(GatewayException::class);
        $this->webhookProcessor->process('mock', ['test' => 'fail'], ['x-mock-signature' => 'invalid']);
    }
}
