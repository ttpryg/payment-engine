<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Entities\PaymentHistory;

interface PaymentHistoryRepositoryInterface
{
    public function save(PaymentHistory $history): void;

    /**
     * @return PaymentHistory[]
     */
    public function findByPaymentId(string $paymentId): array;
}
