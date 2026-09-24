<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\DTOs;

final class GatewayResponse
{
    public function __construct(
        public readonly bool $isSuccessful,
        public readonly ?string $transactionReference = null,
        public readonly ?string $redirectUrl = null,
        public readonly ?string $paymentCode = null,
        public readonly ?array $metadata = null,
        public readonly ?array $rawResponse = null,
        public readonly ?string $errorMessage = null
    ) {}
}
