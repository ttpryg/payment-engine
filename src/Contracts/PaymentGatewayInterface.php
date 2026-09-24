<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\DTOs\GatewayResponse;
use Ttpryg\PaymentEngine\DTOs\GatewayStatusResponse;
use Ttpryg\PaymentEngine\DTOs\RefundResponse;
use Ttpryg\PaymentEngine\DTOs\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\ValueObjects\Money;

interface PaymentGatewayInterface
{
    public function getName(): string;

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse;

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult;

    public function checkStatus(string $transactionReference): GatewayStatusResponse;

    public function refund(Payment $payment, Money $amount, ?string $reason = null): RefundResponse;
}
