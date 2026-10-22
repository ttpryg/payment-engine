<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Application\DTO\CreatePaymentRequest;
use Ttpryg\PaymentEngine\Application\Service\PaymentService;
use Ttpryg\PaymentEngine\Application\Service\PaymentStatusService;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentMethod;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayableReference;
use Ttpryg\PaymentEngine\Domain\ValueObject\PayerReference;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\ManualTransferGateway;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\MockPaymentGateway;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory\MemoryPaymentRepository;

class PaymentServiceTest extends TestCase
{
    private MemoryPaymentRepository $memoryPaymentRepository;

    private MemoryPaymentAttemptRepository $memoryPaymentAttemptRepository;

    private MemoryPaymentHistoryRepository $memoryPaymentHistoryRepository;

    private GatewayManager $gatewayManager;

    private PaymentService $paymentService;

    protected function setUp(): void
    {
        $this->memoryPaymentRepository = new MemoryPaymentRepository;
        $this->memoryPaymentAttemptRepository = new MemoryPaymentAttemptRepository;
        $this->memoryPaymentHistoryRepository = new MemoryPaymentHistoryRepository;

        $this->gatewayManager = new GatewayManager;
        $this->gatewayManager->register(new MockPaymentGateway('mock'));
        $this->gatewayManager->register(new ManualTransferGateway('manual'));

        $paymentStatusService = new PaymentStatusService($this->memoryPaymentRepository, $this->memoryPaymentHistoryRepository);

        $this->paymentService = new PaymentService(
            $this->memoryPaymentRepository,
            $this->memoryPaymentAttemptRepository,
            $this->memoryPaymentHistoryRepository,
            $this->gatewayManager,
            $paymentStatusService
        );
    }

    public function test_create_payment_with_mock_gateway_and_attempts(): void
    {
        $payment = $this->paymentService->createPayment(new CreatePaymentRequest(
            id: 'pay-001',
            payable: new PayableReference('order', 'ord-777'),
            amount: 500000,
            method: PaymentMethod::VIRTUAL_ACCOUNT,
            gatewayProvider: 'mock',
            payer: new PayerReference('user', 'usr-999'),
            fee: 4500
        ));

        $this->assertEquals('pay-001', $payment->id);
        $this->assertEquals(PaymentStatus::PENDING, $payment->status);
        $this->assertEquals(500000, $payment->amount->amount);
        $this->assertEquals(4500, $payment->fee->amount);
        $this->assertEquals(504500, $payment->totalAmount->amount);

        // Verify attempt was created with mock transaction reference
        $attempts = $this->memoryPaymentAttemptRepository->findByPaymentId('pay-001');
        $this->assertCount(1, $attempts);
        $this->assertNotNull($attempts[0]->transactionReference);
        $this->assertStringStartsWith('mock_tx_', $attempts[0]->transactionReference);
        $this->assertNotNull($payment->metadata);
        $this->assertArrayHasKey('redirect_url', $payment->metadata);
        $this->assertNotNull($payment->metadata['redirect_url']);

        // Verify history recorded
        $histories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-001');
        $this->assertCount(1, $histories);
    }

    public function test_get_payments_by_payable_and_payer(): void
    {
        $this->paymentService->createPayment(new CreatePaymentRequest(
            id: 'pay-002',
            payable: new PayableReference('invoice', 'inv-101'),
            amount: 250000,
            gatewayProvider: 'manual',
            payer: new PayerReference('customer', 'cust-55')
        ));

        $payablePayments = $this->paymentService->getPaymentsByPayable('invoice', 'inv-101');
        $this->assertCount(1, $payablePayments);
        $this->assertEquals('pay-002', $payablePayments[0]->id);

        $payerPayments = $this->paymentService->getPaymentsByPayer('customer', 'cust-55');
        $this->assertCount(1, $payerPayments);
    }
}
