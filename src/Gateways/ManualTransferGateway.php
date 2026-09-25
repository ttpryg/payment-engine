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

class ManualTransferGateway implements PaymentGatewayInterface
{
    public function __construct(private readonly string $name = 'manual') {}

    public function getName(): string
    {
        return $this->name;
    }

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse
    {
        $ref = 'manual_'.$payment->paymentNumber->value;

        return new GatewayResponse(
            isSuccessful: true,
            transactionReference: $ref,
            paymentCode: $payment->paymentNumber->value,
            metadata: [
                'type' => 'manual_transfer',
                'bank_account' => $options['bank_account'] ?? 'BCA 1234567890 a/n PT Toko Digital',
                'instruction' => 'Silakan transfer tepat sesuai total amount',
            ]
        );
    }

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult
    {
        $txRef = $payload['transaction_reference'] ?? $payload['payment_number'] ?? null;
        $paidAmount = isset($payload['paid_amount'])
            ? new Money((int) $payload['paid_amount'], $payload['currency'] ?? 'IDR')
            : null;

        return new WebhookVerificationResult(
            isValid: true,
            transactionReference: $txRef,
            eventId: $payload['event_id'] ?? null,
            mappedStatus: PaymentStatus::COMPLETED,
            paidAmount: $paidAmount,
            payloadFingerprint: WebhookEvent::generateFingerprint($payload)
        );
    }

    public function checkStatus(string $transactionReference): GatewayStatusResponse
    {
        return new GatewayStatusResponse(
            mappedStatus: PaymentStatus::PENDING,
            transactionReference: $transactionReference
        );
    }

    public function refund(Payment $payment, Money $money, ?string $reason = null): RefundResponse
    {
        return new RefundResponse(
            isSuccessful: true,
            gatewayRefundId: 'manual_ref_'.bin2hex(random_bytes(6)),
            refundedAmount: $money,
            rawResponse: ['note' => 'Manual refund recorded offline']
        );
    }
}
