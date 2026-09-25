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
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentAttemptRepository $memoryPaymentAttemptRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private MemoryWebhookEventRepository $memoryWebhookEventRepository;

    private ListenerProvider $listenerProvider;

    private WebhookProcessorService $webhookProcessorService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentAttemptRepository = new MemoryPaymentAttemptRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;
        $this->memoryWebhookEventRepository = new MemoryWebhookEventRepository;

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $this->listenerProvider = new ListenerProvider;
        $eventDispatcher = new EventDispatcher($this->listenerProvider);

        $paymentStatusService = new PaymentStatusService($this->memoryPaymentRepository, $this->memoryPaymentHistoryRepository, $eventDispatcher);

        $this->webhookProcessorService = new WebhookProcessorService(
            $gatewayManager,
            $this->memoryWebhookEventRepository,
            $this->memoryPaymentRepository,
            $this->memoryPaymentAttemptRepository,
            $this->memoryPaymentHistoryRepository,
            $paymentStatusService
        );
    }

    public function test_process_webhook_and_deduplicate_webhook_execution(): void
    {
        $eventDispatchedCount = 0;
        $this->listenerProvider->addListener(PaymentCompletedEvent::class, function () use (&$eventDispatchedCount): void {
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
        $this->memoryPaymentRepository->save($payment);

        $paymentAttempt = new PaymentAttempt(
            id: 'att-wh-1',
            paymentId: 'pay-wh-1',
            gatewayProvider: 'mock',
            transactionReference: 'mock_tx_12345',
            status: AttemptStatus::PENDING,
            amount: new Money(300000, 'IDR')
        );
        $this->memoryPaymentAttemptRepository->save($paymentAttempt);

        $payload = [
            'event_id' => 'evt_99999',
            'transaction_reference' => 'mock_tx_12345',
            'status' => 'completed',
            'paid_amount' => 300000,
        ];

        // 2. First Webhook Ingestion
        $webhookVerificationResult = $this->webhookProcessorService->process('mock', $payload);
        $this->assertTrue($webhookVerificationResult->isValid);
        $this->assertEquals(PaymentStatus::COMPLETED, $payment->status);
        $this->assertEquals(1, $eventDispatchedCount);

        $histories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-wh-1');
        $initialHistoryCount = count($histories);
        $this->assertGreaterThan(0, $initialHistoryCount);

        // 3. Second Webhook Ingestion (Duplicate delivery from gateway)
        $res2 = $this->webhookProcessorService->process('mock', $payload);
        $this->assertTrue($res2->isValid);

        // Assert: NO duplicate domain events dispatched!
        $this->assertEquals(1, $eventDispatchedCount);

        // Assert: NO duplicate history records appended!
        $newHistories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-wh-1');
        $this->assertCount($initialHistoryCount, $newHistories);
    }

    public function test_invalid_signature_throws_gateway_exception(): void
    {
        $this->expectException(GatewayException::class);
        $this->webhookProcessorService->process('mock', ['test' => 'fail'], ['x-mock-signature' => 'invalid']);
    }
}
