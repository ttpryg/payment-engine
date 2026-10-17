<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Gateways\XenditGateway;
use Ttpryg\PaymentEngine\Tests\Support\FakeHttpClient;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;

class XenditGatewayTest extends TestCase
{
    private const SECRET_KEY = 'xnd_development_secretkey';

    private const WEBHOOK_TOKEN = 'xendit-callback-token-abc';

    private FakeHttpClient $http;

    private XenditGateway $gateway;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient;
        $this->gateway = new XenditGateway(self::SECRET_KEY, self::WEBHOOK_TOKEN, $this->http);
    }

    public function test_create_transaction_posts_invoice_and_maps_response(): void
    {
        $this->http->queueJson([
            'id' => 'inv-123',
            'external_id' => 'PAY-XND-1',
            'invoice_url' => 'https://checkout.xendit.co/web/inv-123',
            'status' => 'PENDING',
        ]);

        $response = $this->gateway->createTransaction($this->payment());

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.xendit.co/v2/invoices', $request['url']);
        $this->assertSame('Basic '.base64_encode(self::SECRET_KEY.':'), $request['headers']['Authorization']);

        $body = $this->http->lastRequestBody();
        $this->assertSame('PAY-XND-1', $body['external_id']);
        $this->assertSame(250000, $body['amount']);
        $this->assertSame('buyer@example.com', $body['payer_email']);

        $this->assertTrue($response->isSuccessful);
        $this->assertSame('inv-123', $response->transactionReference);
        $this->assertSame('https://checkout.xendit.co/web/inv-123', $response->redirectUrl);
        $this->assertSame('inv-123', $response->metadata['xendit_invoice_id']);
    }

    public function test_create_transaction_returns_failure_on_error_response(): void
    {
        $this->http->queueJson(['error_code' => 'API_VALIDATION_ERROR', 'message' => 'Invalid amount'], 400);

        $response = $this->gateway->createTransaction($this->payment());

        $this->assertFalse($response->isSuccessful);
        $this->assertSame('PAY-XND-1', $response->transactionReference);
        $this->assertStringContainsString('Invalid amount', (string) $response->errorMessage);
    }

    public function test_verify_webhook_accepts_valid_token_and_maps_paid_invoice(): void
    {
        $payload = [
            'id' => 'inv-123',
            'external_id' => 'PAY-XND-1',
            'status' => 'PAID',
            'paid_amount' => 250000,
            'currency' => 'IDR',
        ];

        $result = $this->gateway->verifyWebhook($payload, ['X-Callback-Token' => self::WEBHOOK_TOKEN]);

        $this->assertTrue($result->isValid);
        $this->assertSame('inv-123', $result->transactionReference);
        $this->assertSame(PaymentStatus::COMPLETED, $result->mappedStatus);
        $this->assertTrue($result->paidAmount->equals(new Money(250000, 'IDR')));
    }

    public function test_verify_webhook_rejects_invalid_token(): void
    {
        $result = $this->gateway->verifyWebhook(
            ['id' => 'inv-123', 'status' => 'PAID'],
            ['x-callback-token' => 'wrong-token']
        );

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('callback token', (string) $result->failureReason);
    }

    public function test_verify_webhook_rejects_when_token_not_configured(): void
    {
        $gateway = new XenditGateway(self::SECRET_KEY, null, $this->http);

        $result = $gateway->verifyWebhook(['id' => 'inv-123', 'status' => 'PAID'], ['x-callback-token' => 'anything']);

        $this->assertFalse($result->isValid);
        $this->assertStringContainsString('not configured', (string) $result->failureReason);
    }

    public function test_verify_webhook_maps_expired_invoice(): void
    {
        $result = $this->gateway->verifyWebhook(
            ['id' => 'inv-123', 'status' => 'EXPIRED'],
            ['x-callback-token' => self::WEBHOOK_TOKEN]
        );

        $this->assertTrue($result->isValid);
        $this->assertSame(PaymentStatus::EXPIRED, $result->mappedStatus);
        $this->assertNull($result->paidAmount);
    }

    public function test_check_status_maps_settled_invoice_to_completed(): void
    {
        $this->http->queueJson([
            'id' => 'inv-123',
            'status' => 'SETTLED',
            'paid_amount' => 250000,
            'currency' => 'IDR',
        ]);

        $status = $this->gateway->checkStatus('inv-123');

        $this->assertSame('https://api.xendit.co/v2/invoices/inv-123', $this->http->lastRequest()['url']);
        $this->assertSame(PaymentStatus::COMPLETED, $status->mappedStatus);
        $this->assertTrue($status->paidAmount->equals(new Money(250000, 'IDR')));
    }

    public function test_refund_uses_invoice_id_from_metadata_and_maps_response(): void
    {
        $this->http->queueJson([
            'id' => 'rfd-1',
            'invoice_id' => 'inv-123',
            'amount' => 250000,
            'status' => 'SUCCEEDED',
        ]);

        $payment = $this->payment();
        $payment->metadata = ['xendit_invoice_id' => 'inv-123'];

        $refund = $this->gateway->refund($payment, new Money(250000, 'IDR'));

        $this->assertSame('https://api.xendit.co/refunds', $this->http->lastRequest()['url']);
        $this->assertSame('inv-123', $this->http->lastRequestBody()['invoice_id']);

        $this->assertTrue($refund->isSuccessful);
        $this->assertSame('rfd-1', $refund->gatewayRefundId);
        $this->assertTrue($refund->refundedAmount->equals(new Money(250000, 'IDR')));
    }

    public function test_refund_marks_failed_status_as_unsuccessful(): void
    {
        $this->http->queueJson([
            'id' => 'rfd-2',
            'amount' => 250000,
            'status' => 'FAILED',
            'failure_code' => 'INSUFFICIENT_BALANCE',
        ]);

        $payment = $this->payment();
        $payment->metadata = ['xendit_invoice_id' => 'inv-123'];

        $refund = $this->gateway->refund($payment, new Money(250000, 'IDR'));

        $this->assertFalse($refund->isSuccessful);
        $this->assertStringContainsString('INSUFFICIENT_BALANCE', (string) $refund->errorMessage);
    }

    private function payment(): Payment
    {
        return new Payment(
            id: 'pay-xnd-1',
            paymentNumber: 'PAY-XND-1',
            payable: new PayableReference('order', 'ord-xnd-1'),
            payer: new PayerReference('email', 'buyer@example.com'),
            gatewayProvider: 'xendit',
            status: PaymentStatus::PENDING,
            amount: 250000
        );
    }
}
