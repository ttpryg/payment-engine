<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use DateTimeImmutable;
use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\EventDispatcher\Dispatcher\EventDispatcher;
use Ttpryg\EventDispatcher\Provider\ListenerProvider;
use Ttpryg\PaymentEngine\Contracts\WebhookEventRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Entities\WebhookEvent;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentCompletedEvent;
use Ttpryg\PaymentEngine\Exceptions\HttpClientException;
use Ttpryg\PaymentEngine\Exceptions\RefundAmountExceededException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\MidtransGateway;
use Ttpryg\PaymentEngine\Gateways\MockPaymentGateway;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRefundRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoPaymentRepository;
use Ttpryg\PaymentEngine\Repositories\Pdo\PdoWebhookEventRepository;
use Ttpryg\PaymentEngine\Services\GatewayReconciliationService;
use Ttpryg\PaymentEngine\Services\PaymentRefundService;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\Services\WebhookProcessorService;
use Ttpryg\PaymentEngine\Tests\Support\FakeHttpClient;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class PaymentEdgeCaseTest extends TestCase
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

    public function test_concurrent_duplicate_webhook_hits_unique_constraint_and_is_a_noop(): void
    {
        $completedEvents = 0;
        $listenerProvider = new ListenerProvider;
        $listenerProvider->addListener(PaymentCompletedEvent::class, function () use (&$completedEvents): void {
            $completedEvents++;
        });
        $eventDispatcher = new EventDispatcher($listenerProvider);

        $this->seedPendingPayment('mock', 'pay-race-1', 'PAY-RACE-1', 'mock_tx_race_1');

        $paymentStatusService = new PaymentStatusService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentHistoryRepository,
            $eventDispatcher,
            $this->pdo
        );

        $payload = [
            'event_id' => 'evt_race_1',
            'transaction_reference' => 'mock_tx_race_1',
            'status' => 'completed',
            'paid_amount' => 300000,
        ];

        // First delivery commits and marks the webhook as processed.
        $this->processor($this->pdoWebhookEventRepository, $paymentStatusService)->process('mock', $payload);

        $historyAfterFirst = $this->pdoPaymentHistoryRepository->findByPaymentId('pay-race-1');

        // Second delivery represents a request that read the idempotency tables
        // *before* the first committed (TOCTOU race): the read checks miss the row,
        // but the INSERT then collides with the database unique constraint.
        $racingRepository = new class($this->pdoWebhookEventRepository) implements WebhookEventRepositoryInterface
        {
            public function __construct(private readonly WebhookEventRepositoryInterface $inner) {}

            public function save(WebhookEvent $webhookEvent): void
            {
                $this->inner->save($webhookEvent);
            }

            public function findById(string $id): ?WebhookEvent
            {
                return null;
            }

            public function findByFingerprint(string $gatewayProvider, string $fingerprint): ?WebhookEvent
            {
                return null;
            }

            public function findByEventId(string $gatewayProvider, string $eventId): ?WebhookEvent
            {
                return null;
            }
        };

        $result = $this->processor($racingRepository, $paymentStatusService)->process('mock', $payload);

        $this->assertTrue($result->isValid);
        $this->assertSame(1, $completedEvents);

        $fresh = $this->pdoPaymentRepository->findById('pay-race-1');
        $this->assertNotNull($fresh);
        $this->assertSame(PaymentStatus::COMPLETED, $fresh->status);

        // No duplicate history rows and exactly one persisted webhook event.
        $this->assertCount(count($historyAfterFirst), $this->pdoPaymentHistoryRepository->findByPaymentId('pay-race-1'));
        $this->assertSame(1, $this->countWebhookEvents('mock'));
    }

    public function test_partial_refund_ladder_reaches_paid_amount_then_rejects_overflow(): void
    {
        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-ladder-1',
            paymentNumber: 'PAY-LADDER-1',
            payable: new PayableReference('order', 'ord-ladder-1'),
            gatewayProvider: 'mock',
            status: PaymentStatus::COMPLETED,
            amount: 100000,
            paidAmount: new Money(100000, 'IDR')
        ));

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        $refundService = new PaymentRefundService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentRefundRepository,
            $this->pdoPaymentHistoryRepository,
            $gatewayManager,
            null,
            $this->pdo
        );

        foreach ([30000, 30000, 30000] as $amount) {
            $refund = $refundService->issueRefund('pay-ladder-1', new Money($amount, 'IDR'));
            $this->assertSame($amount, $refund->amount->amount);

            $fresh = $this->pdoPaymentRepository->findById('pay-ladder-1');
            $this->assertSame(PaymentStatus::PARTIALLY_REFUNDED, $fresh?->status);
        }

        $refundedBeforeFinal = $this->pdoPaymentRepository->findById('pay-ladder-1');
        $this->assertSame(90000, $refundedBeforeFinal?->refundedAmount->amount);

        // The final refund exactly consumes the remaining refundable balance.
        $refundService->issueRefund('pay-ladder-1', new Money(10000, 'IDR'));

        $final = $this->pdoPaymentRepository->findById('pay-ladder-1');
        $this->assertSame(PaymentStatus::REFUNDED, $final?->status);
        $this->assertSame(100000, $final?->refundedAmount->amount);
        $this->assertCount(4, $this->pdoPaymentRefundRepository->findByPaymentId('pay-ladder-1'));

        // Any further refund must be rejected and leave the payment untouched.
        try {
            $refundService->issueRefund('pay-ladder-1', new Money(1, 'IDR'));
            $this->fail('Expected RefundAmountExceededException');
        } catch (RefundAmountExceededException) {
            // expected
        }

        $this->assertCount(4, $this->pdoPaymentRefundRepository->findByPaymentId('pay-ladder-1'));
        $this->assertSame(100000, $this->pdoPaymentRepository->findById('pay-ladder-1')?->refundedAmount->amount);
    }

    public function test_reconciliation_skips_payment_when_gateway_transport_times_out(): void
    {
        $now = new DateTimeImmutable('2026-10-17 12:00:00');

        $this->pdoPaymentRepository->save(new Payment(
            id: 'pay-timeout-1',
            paymentNumber: 'PAY-TIMEOUT-1',
            payable: new PayableReference('order', 'ord-timeout-1'),
            gatewayProvider: 'midtrans',
            status: PaymentStatus::PENDING,
            amount: 250000,
            expiresAt: $now->modify('-1 minute')
        ));

        $this->pdoPaymentAttemptRepository->save(new PaymentAttempt(
            id: 'att-timeout-1',
            paymentId: 'pay-timeout-1',
            gatewayProvider: 'midtrans',
            transactionReference: 'PAY-TIMEOUT-1',
            status: AttemptStatus::PENDING,
            amount: new Money(250000, 'IDR')
        ));

        $http = new FakeHttpClient;
        $http->throwOnNextRequest(HttpClientException::transportError('[28] Operation timed out'));

        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MidtransGateway('SB-Mid-server-testkey', false, $http));

        $paymentStatusService = new PaymentStatusService(
            $this->pdoPaymentRepository,
            $this->pdoPaymentHistoryRepository,
            null,
            $this->pdo
        );

        $reconciliationService = new GatewayReconciliationService(
            $gatewayManager,
            $this->pdoPaymentRepository,
            $this->pdoPaymentAttemptRepository,
            $paymentStatusService,
            $this->pdo
        );

        $reconciled = $reconciliationService->reconcile($now);

        $this->assertCount(0, $reconciled);
        $this->assertSame(PaymentStatus::PENDING, $this->pdoPaymentRepository->findById('pay-timeout-1')?->status);
        $this->assertSame(AttemptStatus::PENDING, $this->pdoPaymentAttemptRepository->findByTransactionReference('midtrans', 'PAY-TIMEOUT-1')?->status);
        $this->assertCount(0, $this->pdoPaymentHistoryRepository->findByPaymentId('pay-timeout-1'));
    }

    private function seedPendingPayment(string $provider, string $paymentId, string $paymentNumber, string $reference): void
    {
        $this->pdoPaymentRepository->save(new Payment(
            id: $paymentId,
            paymentNumber: $paymentNumber,
            payable: new PayableReference('order', 'ord-'.$paymentId),
            gatewayProvider: $provider,
            status: PaymentStatus::PENDING,
            amount: 300000
        ));

        $this->pdoPaymentAttemptRepository->save(new PaymentAttempt(
            id: 'att-'.$paymentId,
            paymentId: $paymentId,
            gatewayProvider: $provider,
            transactionReference: $reference,
            status: AttemptStatus::PENDING,
            amount: new Money(300000, 'IDR')
        ));
    }

    private function processor(
        WebhookEventRepositoryInterface $webhookEventRepository,
        PaymentStatusService $paymentStatusService
    ): WebhookProcessorService {
        $gatewayManager = new GatewayManager;
        $gatewayManager->register(new MockPaymentGateway('mock'));

        return new WebhookProcessorService(
            $gatewayManager,
            $webhookEventRepository,
            $this->pdoPaymentRepository,
            $this->pdoPaymentAttemptRepository,
            $this->pdoPaymentHistoryRepository,
            $paymentStatusService,
            $this->pdo
        );
    }

    private function countWebhookEvents(string $provider): int
    {
        $statement = $this->pdo->prepare('SELECT COUNT(*) FROM webhook_events WHERE gateway_provider = :provider');
        $statement->execute(['provider' => $provider]);

        return (int) $statement->fetchColumn();
    }
}
