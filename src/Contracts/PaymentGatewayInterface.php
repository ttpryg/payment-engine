<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Application\DTO\GatewayResponse;
use Ttpryg\PaymentEngine\Application\DTO\GatewayStatusResponse;
use Ttpryg\PaymentEngine\Application\DTO\RefundResponse;
use Ttpryg\PaymentEngine\Application\DTO\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\ValueObject\Money;

interface PaymentGatewayInterface
{
    public function getName(): string;

    /**
     * @param  array<string, mixed>  $options
     */
    public function createTransaction(Payment $payment, array $options = []): GatewayResponse;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, string>  $headers
     */
    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult;

    public function checkStatus(string $transactionReference): GatewayStatusResponse;

    public function refund(Payment $payment, Money $money, ?string $reason = null): RefundResponse;
}
