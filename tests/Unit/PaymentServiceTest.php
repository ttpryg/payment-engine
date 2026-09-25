<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Enums\PaymentMethod;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\Gateways\ManualTransferGateway;
use Ttpryg\PaymentEngine\Gateways\MockPaymentGateway;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentAttemptRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentHistoryRepository;
use Ttpryg\PaymentEngine\Repositories\Memory\MemoryPaymentRepository;
use Ttpryg\PaymentEngine\Services\PaymentService;
use Ttpryg\PaymentEngine\Services\PaymentStatusService;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;

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
        $payment = $this->paymentService->createPayment(
            id: 'pay-001',
            amount: 500000,
            method: PaymentMethod::VIRTUAL_ACCOUNT,
            gatewayProvider: 'mock',
            fee: 4500,
            payable: new PayableReference('order', 'ord-777'),
            payer: new PayerReference('user', 'usr-999')
        );

        $this->assertEquals('pay-001', $payment->id);
        $this->assertEquals(PaymentStatus::PENDING, $payment->status);
        $this->assertEquals(500000, $payment->amount->amount);
        $this->assertEquals(4500, $payment->fee->amount);
        $this->assertEquals(504500, $payment->totalAmount->amount);

        // Verify attempt was created with mock transaction reference
        $attempts = $this->memoryPaymentAttemptRepository->findByPaymentId('pay-001');
        $this->assertCount(1, $attempts);
        $this->assertStringStartsWith('mock_tx_', $attempts[0]->transactionReference);
        $this->assertNotNull($payment->metadata['redirect_url']);

        // Verify history recorded
        $histories = $this->memoryPaymentHistoryRepository->findByPaymentId('pay-001');
        $this->assertCount(1, $histories);
    }

    public function test_get_payments_by_payable_and_payer(): void
    {
        $this->paymentService->createPayment(
            id: 'pay-002',
            amount: 250000,
            gatewayProvider: 'manual',
            payable: new PayableReference('invoice', 'inv-101'),
            payer: new PayerReference('customer', 'cust-55')
        );

        $payablePayments = $this->paymentService->getPaymentsByPayable('invoice', 'inv-101');
        $this->assertCount(1, $payablePayments);
        $this->assertEquals('pay-002', $payablePayments[0]->id);

        $payerPayments = $this->paymentService->getPaymentsByPayer('customer', 'cust-55');
        $this->assertCount(1, $payerPayments);
    }
}
