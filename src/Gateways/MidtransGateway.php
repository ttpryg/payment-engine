<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Gateways;

use Ttpryg\PaymentEngine\Application\DTO\GatewayResponse;
use Ttpryg\PaymentEngine\Application\DTO\GatewayStatusResponse;
use Ttpryg\PaymentEngine\Application\DTO\RefundResponse;
use Ttpryg\PaymentEngine\Application\DTO\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Contracts\HttpClientInterface;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\WebhookEvent;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Exception\GatewayException;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

class MidtransGateway extends AbstractHttpGateway
{
    private const SANDBOX_BASE_URL = 'https://api.sandbox.midtrans.com';

    private const PRODUCTION_BASE_URL = 'https://api.midtrans.com';

    public function __construct(
        private readonly string $serverKey,
        private readonly bool $isProduction = false,
        ?HttpClientInterface $httpClient = null,
        private readonly string $name = 'midtrans'
    ) {
        parent::__construct($httpClient);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse
    {
        $orderId = $payment->paymentNumber->value;

        $payload = [
            'transaction_details' => [
                'order_id' => $orderId,
                'gross_amount' => $payment->totalAmount->amount,
            ],
        ];

        foreach (['customer' => 'customer_details', 'items' => 'item_details', 'expiry' => 'expiry', 'callbacks' => 'callbacks'] as $option => $field) {
            if (isset($options[$option])) {
                $payload[$field] = $options[$option];
            }
        }

        $response = $this->request(
            'POST',
            $this->baseUrl().'/snap/v1/transactions',
            ['Authorization' => $this->basicAuth($this->serverKey)],
            $payload
        );

        $data = $this->decode($response);

        if (! $response->isSuccessful()) {
            return new GatewayResponse(
                isSuccessful: false,
                transactionReference: $orderId,
                rawResponse: $data,
                errorMessage: $this->extractErrorMessage($data) ?? 'Midtrans Snap transaction creation failed.'
            );
        }

        return new GatewayResponse(
            isSuccessful: true,
            transactionReference: $orderId,
            redirectUrl: $this->firstString($data, ['redirect_url']),
            metadata: [
                'provider' => $this->name,
                'snap_token' => $this->firstString($data, ['token']),
            ],
            rawResponse: $data
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        $orderId = isset($payload['order_id']) ? (string) $payload['order_id'] : null;
        $statusCode = isset($payload['status_code']) ? (string) $payload['status_code'] : null;
        $grossAmount = isset($payload['gross_amount']) ? (string) $payload['gross_amount'] : null;
        $signatureKey = isset($payload['signature_key']) ? (string) $payload['signature_key'] : null;

        if ($orderId === null || $statusCode === null || $grossAmount === null || $signatureKey === null) {
            return new WebhookVerificationResult(
                isValid: false,
                transactionReference: $orderId,
                failureReason: 'Missing required Midtrans signature fields.'
            );
        }

        $expectedSignature = hash('sha512', $orderId.$statusCode.$grossAmount.$this->serverKey);

        if (! hash_equals($expectedSignature, $signatureKey)) {
            return new WebhookVerificationResult(
                isValid: false,
                transactionReference: $orderId,
                failureReason: 'Invalid Midtrans signature_key.'
            );
        }

        $mappedStatus = $this->mapTransactionStatus(
            isset($payload['transaction_status']) ? (string) $payload['transaction_status'] : '',
            isset($payload['fraud_status']) ? (string) $payload['fraud_status'] : ''
        );

        $paidAmount = null;
        if ($mappedStatus === PaymentStatus::COMPLETED) {
            $paidAmount = new Money(
                (int) round((float) $grossAmount),
                isset($payload['currency']) ? (string) $payload['currency'] : 'IDR'
            );
        }

        return new WebhookVerificationResult(
            isValid: true,
            transactionReference: $orderId,
            mappedStatus: $mappedStatus,
            paidAmount: $paidAmount,
            payloadFingerprint: WebhookEvent::generateFingerprint($payload)
        );
    }

    public function checkStatus(string $transactionReference): GatewayStatusResponse
    {
        $response = $this->request(
            'GET',
            $this->baseUrl().'/v2/'.$transactionReference.'/status',
            ['Authorization' => $this->basicAuth($this->serverKey)]
        );

        $data = $this->decode($response);

        if (! $response->isSuccessful()) {
            throw GatewayException::failed($this->name, $this->extractErrorMessage($data) ?? 'Failed to fetch the Midtrans transaction status.');
        }

        $mappedStatus = $this->mapTransactionStatus(
            isset($data['transaction_status']) ? (string) $data['transaction_status'] : '',
            isset($data['fraud_status']) ? (string) $data['fraud_status'] : ''
        );

        $paidAmount = null;
        if ($mappedStatus === PaymentStatus::COMPLETED && isset($data['gross_amount']) && is_numeric($data['gross_amount'])) {
            $paidAmount = new Money(
                (int) round((float) $data['gross_amount']),
                isset($data['currency']) ? (string) $data['currency'] : 'IDR'
            );
        }

        return new GatewayStatusResponse(
            mappedStatus: $mappedStatus,
            transactionReference: $transactionReference,
            paidAmount: $paidAmount,
            rawResponse: $data
        );
    }

    public function refund(Payment $payment, Money $money, ?string $reason = null): RefundResponse
    {
        $orderId = $payment->paymentNumber->value;

        $response = $this->request(
            'POST',
            $this->baseUrl().'/v2/'.$orderId.'/refund',
            ['Authorization' => $this->basicAuth($this->serverKey)],
            [
                'refund_key' => $payment->id.'-'.strtoupper(bin2hex(random_bytes(6))),
                'amount' => $money->amount,
                'reason' => $reason ?? 'Refund requested',
            ]
        );

        $data = $this->decode($response);

        if (! $response->isSuccessful()) {
            return new RefundResponse(
                isSuccessful: false,
                rawResponse: $data,
                errorMessage: $this->extractErrorMessage($data) ?? 'Midtrans refund request failed.'
            );
        }

        return new RefundResponse(
            isSuccessful: true,
            gatewayRefundId: $this->firstString($data, ['refund_key', 'refund_chargeback_id', 'transaction_id']),
            refundedAmount: isset($data['refund_amount']) && is_numeric($data['refund_amount'])
                ? new Money((int) round((float) $data['refund_amount']), $money->currency)
                : $money,
            rawResponse: $data
        );
    }

    private function baseUrl(): string
    {
        return $this->isProduction ? self::PRODUCTION_BASE_URL : self::SANDBOX_BASE_URL;
    }

    private function mapTransactionStatus(string $transactionStatus, string $fraudStatus = ''): PaymentStatus
    {
        return match (strtolower($transactionStatus)) {
            'capture' => strtolower($fraudStatus) === 'challenge' ? PaymentStatus::PENDING : PaymentStatus::COMPLETED,
            'settlement' => PaymentStatus::COMPLETED,
            'authorize' => PaymentStatus::AUTHORIZED,
            'pending' => PaymentStatus::PENDING,
            'deny' => PaymentStatus::FAILED,
            'cancel' => PaymentStatus::CANCELLED,
            'expire' => PaymentStatus::EXPIRED,
            'refund' => PaymentStatus::REFUNDED,
            'partial_refund' => PaymentStatus::PARTIALLY_REFUNDED,
            default => PaymentStatus::PENDING,
        };
    }
}
