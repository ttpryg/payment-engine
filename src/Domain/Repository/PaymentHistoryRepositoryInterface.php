<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Repository;

use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;

interface PaymentHistoryRepositoryInterface
{
    public function save(PaymentHistory $paymentHistory): void;

    /**
     * @return PaymentHistory[]
     */
    public function findByPaymentId(string $paymentId): array;
}
