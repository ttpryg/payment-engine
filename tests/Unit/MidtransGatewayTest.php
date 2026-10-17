<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Unit;

use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Exceptions\GatewayException;
use Ttpryg\PaymentEngine\Exceptions\HttpClientException;
use Ttpryg\PaymentEngine\Gateways\MidtransGateway;
use Ttpryg\PaymentEngine\Tests\Support\FakeHttpClient;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;

class MidtransGatewayTest extends TestCase
{
    private const SERVER_KEY = 'SB-Mid-server-testkey';

    private FakeHttpClient $http;

    private MidtransGateway $gateway;

    protected function setUp(): void
    {
        $this->http = new FakeHttpClient;
        $this->gateway = new MidtransGateway(self::SERVER_KEY, false, $this->http);
    }

    public function test_create_transaction_posts_snap_payload_and_maps_response(): void
    {
        $this->http->queueJson([
            'token' => 'snap-token-123',
            'redirect_url' => 'https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-123',
        ]);

        $response = $this->gateway->createTransaction($this->payment());

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.sandbox.midtrans.com/snap/v1/transactions', $request['url']);
        $this->assertSame('Basic '.base64_encode(self::SERVER_KEY.':'), $request['headers']['Authorization']);

        $body = $this->http->lastRequestBody();
        $this->assertSame('PAY-MID-1', $body['transaction_details']['order_id']);
        $this->assertSame(250000, $body['transaction_details']['gross_amount']);

        $this->assertTrue($response->isSuccessful);
        $this->assertSame('PAY-MID-1', $response->transactionReference);
        $this->assertSame('https://app.sandbox.midtrans.com/snap/v2/vtweb/snap-token-123', $response->redirectUrl);
        $this->assertSame('snap-token-123', $response->metadata['snap_token']);
    }

    public function test_create_transaction_uses_production_base_url_when_enabled(): void
    {
        $gateway = new MidtransGateway(self::SERVER_KEY, true, $this->http);
        $this->http->queueJson(['token' => 't', 'redirect_url' => 'https://app.midtrans.com/x']);

        $gateway->createTransaction($this->payment());

        $this->assertSame('https://api.midtrans.com/snap/v1/transactions', $this->http->lastRequest()['url']);
    }

    public function test_create_transaction_returns_failure_on_error_response(): void
    {
        $this->http->queueJson(['error_messages' => ['Invalid amount']], 400);

        $response = $this->gateway->createTransaction($this->payment());

        $this->assertFalse($response->isSuccessful);
        $this->assertSame('PAY-MID-1', $response->transactionReference);
        $this->assertStringContainsString('Invalid amount', (string) $response->errorMessage);
    }

    public function test_verify_webhook_accepts_valid_signature_and_maps_settlement(): void
    {
        $payload = $this->signedNotification([
            'transaction_status' => 'settlement',
        ]);

        $result = $this->gateway->verifyWebhook($payload);

        $this->assertTrue($result->isValid);
        $this->assertSame('PAY-MID-1', $result->transactionReference);
        $this->assertSame(PaymentStatus::COMPLETED, $result->mappedStatus);
        $this->assertTrue($result->paidAmount->equals(new Money(250000, 'IDR')));
        $this->assertNotNull($result->payloadFingerprint);
    }

    public function test_verify_webhook_rejects_tampered_signature(): void
    {
        $payload = $this->signedNotification(['transaction_status' => 'settlement']);
        $payload['gross_amount'] = '1.00';

        $result = $this->gateway->verifyWebhook($payload);

        $this->assertFalse($result->isValid);
        $this->assertSame('PAY-MID-1', $result->transactionReference);
        $this->assertStringContainsString('signature', (string) $result->failureReason);
    }

    public function test_verify_webhook_maps_challenged_capture_to_pending(): void
    {
        $payload = $this->signedNotification([
            'transaction_status' => 'capture',
            'fraud_status' => 'challenge',
        ]);

        $result = $this->gateway->verifyWebhook($payload);

        $this->assertTrue($result->isValid);
        $this->assertSame(PaymentStatus::PENDING, $result->mappedStatus);
        $this->assertNull($result->paidAmount);
    }

    public function test_verify_webhook_rejects_missing_signature_fields(): void
    {
        $result = $this->gateway->verifyWebhook(['transaction_status' => 'settlement']);

        $this->assertFalse($result->isValid);
        $this->assertSame('Missing required Midtrans signature fields.', $result->failureReason);
    }

    public function test_check_status_maps_settlement_to_completed(): void
    {
        $this->http->queueJson([
            'transaction_status' => 'settlement',
            'gross_amount' => '250000.00',
            'currency' => 'IDR',
        ]);

        $status = $this->gateway->checkStatus('PAY-MID-1');

        $this->assertSame('https://api.sandbox.midtrans.com/v2/PAY-MID-1/status', $this->http->lastRequest()['url']);
        $this->assertSame(PaymentStatus::COMPLETED, $status->mappedStatus);
        $this->assertTrue($status->paidAmount->equals(new Money(250000, 'IDR')));
    }

    public function test_check_status_throws_on_error_response(): void
    {
        $this->http->queueJson(['status_message' => 'Transaction not found'], 404);

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Transaction not found');

        $this->gateway->checkStatus('PAY-MID-1');
    }

    public function test_refund_posts_refund_request_and_maps_response(): void
    {
        $this->http->queueJson([
            'refund_key' => 'ref-key-1',
            'refund_amount' => '250000.00',
            'status_code' => '200',
        ]);

        $refund = $this->gateway->refund($this->payment(), new Money(250000, 'IDR'), 'Customer request');

        $request = $this->http->lastRequest();
        $this->assertSame('POST', $request['method']);
        $this->assertSame('https://api.sandbox.midtrans.com/v2/PAY-MID-1/refund', $request['url']);

        $body = $this->http->lastRequestBody();
        $this->assertSame(250000, $body['amount']);
        $this->assertSame('Customer request', $body['reason']);

        $this->assertTrue($refund->isSuccessful);
        $this->assertSame('ref-key-1', $refund->gatewayRefundId);
        $this->assertTrue($refund->refundedAmount->equals(new Money(250000, 'IDR')));
    }

    public function test_transport_errors_are_wrapped_in_gateway_exception(): void
    {
        $this->http->throwOnNextRequest(HttpClientException::transportError('[7] Connection refused'));

        $this->expectException(GatewayException::class);
        $this->expectExceptionMessage('Connection refused');

        $this->gateway->createTransaction($this->payment());
    }

    private function payment(): Payment
    {
        return new Payment(
            id: 'pay-mid-1',
            paymentNumber: 'PAY-MID-1',
            payable: new PayableReference('order', 'ord-mid-1'),
            gatewayProvider: 'midtrans',
            status: PaymentStatus::PENDING,
            amount: 250000
        );
    }

    /**
     * @param  array<string, mixed>  $overrides
     * @return array<string, mixed>
     */
    private function signedNotification(array $overrides = []): array
    {
        $payload = array_merge([
            'order_id' => 'PAY-MID-1',
            'status_code' => '200',
            'gross_amount' => '250000.00',
            'currency' => 'IDR',
            'transaction_id' => 'tx-mid-1',
        ], $overrides);

        $payload['signature_key'] = hash(
            'sha512',
            $payload['order_id'].$payload['status_code'].$payload['gross_amount'].self::SERVER_KEY
        );

        return $payload;
    }
}
