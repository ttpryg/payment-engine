<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Entities\Payment;

interface PaymentRepositoryInterface
{
    public function save(Payment $payment): void;

    public function findById(string $id): ?Payment;

    public function findByPaymentNumber(string $paymentNumber): ?Payment;

    /**
     * @return Payment[]
     */
    public function findByPayable(string $payableType, string $payableId): array;

    /**
     * @return Payment[]
     */
    public function findByPayer(string $payerType, string $payerId): array;

    public function delete(string $id): bool;
}
