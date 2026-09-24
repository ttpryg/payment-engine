<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Gateways;

use Ttpryg\PaymentEngine\Contracts\PaymentGatewayInterface;
use Ttpryg\PaymentEngine\DTOs\GatewayResponse;
use Ttpryg\PaymentEngine\DTOs\GatewayStatusResponse;
use Ttpryg\PaymentEngine\DTOs\RefundResponse;
use Ttpryg\PaymentEngine\DTOs\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\WebhookEvent;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class MockPaymentGateway implements PaymentGatewayInterface
{
    public function __construct(
        private readonly string $name = 'mock',
        private readonly bool $shouldSucceed = true
    ) {}

    public function getName(): string
    {
        return $this->name;
    }

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse
    {
        if (! $this->shouldSucceed) {
            return new GatewayResponse(
                isSuccessful: false,
                errorMessage: 'Mock transaction failed as configured'
            );
        }

        $txRef = 'mock_tx_'.bin2hex(random_bytes(6));

        return new GatewayResponse(
            isSuccessful: true,
            transactionReference: $txRef,
            redirectUrl: "https://mock-payment.example.com/pay/{$txRef}",
            paymentCode: 'MOCK-VA-'.substr($payment->paymentNumber->value, -6),
            metadata: [
                'provider' => $this->name,
                'mock_mode' => true,
            ],
            rawResponse: ['status' => 'created', 'id' => $txRef]
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        if (isset($headers['x-mock-signature']) && $headers['x-mock-signature'] === 'invalid') {
            return new WebhookVerificationResult(
                isValid: false,
                failureReason: 'Invalid mock signature'
            );
        }

        $txRef = $payload['transaction_reference'] ?? $payload['id'] ?? null;
        $eventId = $payload['event_id'] ?? null;
        $statusStr = $payload['status'] ?? 'completed';

        $mappedStatus = match ($statusStr) {
            'settled', 'success', 'completed' => PaymentStatus::COMPLETED,
            'authorized' => PaymentStatus::AUTHORIZED,
            'captured' => PaymentStatus::CAPTURED,
            'failed' => PaymentStatus::FAILED,
            'expired' => PaymentStatus::EXPIRED,
            'cancelled' => PaymentStatus::CANCELLED,
            default => PaymentStatus::PENDING,
        };

        $paidAmount = isset($payload['paid_amount'])
            ? new Money((int) $payload['paid_amount'], $payload['currency'] ?? 'IDR')
            : null;

        return new WebhookVerificationResult(
            isValid: true,
            transactionReference: $txRef,
            eventId: $eventId,
            mappedStatus: $mappedStatus,
            paidAmount: $paidAmount,
            payloadFingerprint: WebhookEvent::generateFingerprint($payload)
        );
    }

    public function checkStatus(string $transactionReference): GatewayStatusResponse
    {
        return new GatewayStatusResponse(
            mappedStatus: PaymentStatus::COMPLETED,
            transactionReference: $transactionReference,
            rawResponse: ['status' => 'settled', 'tx' => $transactionReference]
        );
    }

    public function refund(Payment $payment, Money $amount, ?string $reason = null): RefundResponse
    {
        if (! $this->shouldSucceed) {
            return new RefundResponse(
                isSuccessful: false,
                errorMessage: 'Mock refund failed'
            );
        }

        $refundId = 'mock_ref_'.bin2hex(random_bytes(6));

        return new RefundResponse(
            isSuccessful: true,
            gatewayRefundId: $refundId,
            refundedAmount: $amount,
            rawResponse: ['refund_id' => $refundId, 'amount' => $amount->amount]
        );
    }
}
