<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Entities\PaymentAttempt;

interface PaymentAttemptRepositoryInterface
{
    public function save(PaymentAttempt $paymentAttempt): void;

    public function findById(string $id): ?PaymentAttempt;

    /**
     * @return PaymentAttempt[]
     */
    public function findByPaymentId(string $paymentId): array;

    public function findByTransactionReference(string $gatewayProvider, string $transactionReference): ?PaymentAttempt;
}
