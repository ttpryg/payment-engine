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
use Ttpryg\PaymentEngine\Domain\ValueObject\PayerReference;

class XenditGateway extends AbstractHttpGateway
{
    private const BASE_URL = 'https://api.xendit.co';

    public function __construct(
        private readonly string $secretKey,
        private readonly ?string $webhookToken = null,
        ?HttpClientInterface $httpClient = null,
        private readonly string $name = 'xendit'
    ) {
        parent::__construct($httpClient);
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse
    {
        $externalId = $payment->paymentNumber->value;

        $payload = [
            'external_id' => $externalId,
            'amount' => $payment->totalAmount->amount,
            'currency' => $payment->totalAmount->currency,
            'description' => $options['description'] ?? "Payment {$externalId}",
        ];

        $payerEmail = $options['payer_email'] ?? $this->payerEmail($payment);
        if ($payerEmail !== null) {
            $payload['payer_email'] = $payerEmail;
        }

        foreach (['invoice_duration', 'success_redirect_url', 'failure_redirect_url', 'items'] as $option) {
            if (isset($options[$option])) {
                $payload[$option] = $options[$option];
            }
        }

        $response = $this->request(
            'POST',
            self::BASE_URL.'/v2/invoices',
            ['Authorization' => $this->basicAuth($this->secretKey)],
            $payload
        );

        $data = $this->decode($response);

        if (! $response->isSuccessful()) {
            return new GatewayResponse(
                isSuccessful: false,
                transactionReference: $externalId,
                rawResponse: $data,
                errorMessage: $this->extractErrorMessage($data) ?? 'Xendit invoice creation failed.'
            );
        }

        $invoiceId = $this->firstString($data, ['id']);

        return new GatewayResponse(
            isSuccessful: $invoiceId !== null,
            transactionReference: $invoiceId,
            redirectUrl: $this->firstString($data, ['invoice_url']),
            metadata: [
                'provider' => $this->name,
                'xendit_external_id' => $externalId,
                'xendit_invoice_id' => $invoiceId,
            ],
            rawResponse: $data,
            errorMessage: $invoiceId === null ? 'Xendit did not return an invoice id.' : null
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        if ($this->webhookToken === null || $this->webhookToken === '') {
            return new WebhookVerificationResult(
                isValid: false,
                failureReason: 'Xendit webhook verification token is not configured.'
            );
        }

        $callbackToken = $this->header($headers, 'x-callback-token');

        if ($callbackToken === null || ! hash_equals($this->webhookToken, $callbackToken)) {
            return new WebhookVerificationResult(
                isValid: false,
                failureReason: 'Invalid Xendit callback token.'
            );
        }

        $invoiceId = isset($payload['id']) ? (string) $payload['id'] : null;

        if ($invoiceId === null) {
            return new WebhookVerificationResult(
                isValid: false,
                failureReason: 'Missing Xendit invoice id in the webhook payload.'
            );
        }

        $mappedStatus = $this->mapInvoiceStatus(
            isset($payload['status']) ? (string) $payload['status'] : ''
        );

        $paidAmount = null;
        if ($mappedStatus === PaymentStatus::COMPLETED) {
            $paidAmount = $this->resolveAmount($payload);
        }

        return new WebhookVerificationResult(
            isValid: true,
            transactionReference: $invoiceId,
            mappedStatus: $mappedStatus,
            paidAmount: $paidAmount,
            payloadFingerprint: WebhookEvent::generateFingerprint($payload)
        );
    }

    public function checkStatus(string $transactionReference): GatewayStatusResponse
    {
        $response = $this->request(
            'GET',
            self::BASE_URL.'/v2/invoices/'.$transactionReference,
            ['Authorization' => $this->basicAuth($this->secretKey)]
        );

        $data = $this->decode($response);

        if (! $response->isSuccessful()) {
            throw GatewayException::failed($this->name, $this->extractErrorMessage($data) ?? 'Failed to fetch the Xendit invoice status.');
        }

        $mappedStatus = $this->mapInvoiceStatus(
            isset($data['status']) ? (string) $data['status'] : ''
        );

        return new GatewayStatusResponse(
            mappedStatus: $mappedStatus,
            transactionReference: $transactionReference,
            paidAmount: $mappedStatus === PaymentStatus::COMPLETED ? $this->resolveAmount($data) : null,
            rawResponse: $data
        );
    }

    public function refund(Payment $payment, Money $money, ?string $reason = null): RefundResponse
    {
        $response = $this->request(
            'POST',
            self::BASE_URL.'/refunds',
            ['Authorization' => $this->basicAuth($this->secretKey)],
            [
                'invoice_id' => $this->invoiceIdFor($payment),
                'amount' => $money->amount,
                'reason' => $reason ?? 'REQUESTED_BY_CUSTOMER',
            ]
        );

        $data = $this->decode($response);

        if (! $response->isSuccessful()) {
            return new RefundResponse(
                isSuccessful: false,
                rawResponse: $data,
                errorMessage: $this->extractErrorMessage($data) ?? 'Xendit refund request failed.'
            );
        }

        $status = isset($data['status']) ? strtoupper((string) $data['status']) : '';
        $isFailed = $status === 'FAILED';

        return new RefundResponse(
            isSuccessful: ! $isFailed,
            gatewayRefundId: $this->firstString($data, ['id']),
            refundedAmount: isset($data['amount']) && is_numeric($data['amount'])
                ? new Money((int) $data['amount'], $money->currency)
                : $money,
            rawResponse: $data,
            errorMessage: $isFailed ? ($this->extractErrorMessage($data) ?? 'Xendit refund failed.') : null
        );
    }

    private function mapInvoiceStatus(string $status): PaymentStatus
    {
        return match (strtoupper($status)) {
            'PAID', 'SETTLED', 'SUCCEEDED' => PaymentStatus::COMPLETED,
            'EXPIRED' => PaymentStatus::EXPIRED,
            'FAILED' => PaymentStatus::FAILED,
            'PENDING' => PaymentStatus::PENDING,
            default => PaymentStatus::PENDING,
        };
    }

    /**
     * @param  array<string, mixed>  $data
     */
    private function resolveAmount(array $data): ?Money
    {
        $amount = $data['paid_amount'] ?? $data['amount'] ?? null;

        if (! is_numeric($amount)) {
            return null;
        }

        return new Money(
            (int) $amount,
            isset($data['currency']) ? (string) $data['currency'] : 'IDR'
        );
    }

    private function invoiceIdFor(Payment $payment): string
    {
        $invoiceId = $payment->metadata['xendit_invoice_id'] ?? null;

        if (is_string($invoiceId) && $invoiceId !== '') {
            return $invoiceId;
        }

        return $payment->paymentNumber->value;
    }

    private function payerEmail(Payment $payment): ?string
    {
        if (! $payment->payer instanceof PayerReference) {
            return null;
        }

        return strtolower($payment->payer->type) === 'email' ? $payment->payer->id : null;
    }
}
