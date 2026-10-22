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

    public function createTransaction(Payment $payment, array $options = []): GatewayResponse;

    public function verifyWebhook(array $payload, array $headers = []): WebhookVerificationResult;

    public function checkStatus(string $transactionReference): GatewayStatusResponse;

    public function refund(Payment $payment, Money $money, ?string $reason = null): RefundResponse;
}
