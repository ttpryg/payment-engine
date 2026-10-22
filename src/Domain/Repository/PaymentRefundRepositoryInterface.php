<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Repository;

use Ttpryg\PaymentEngine\Domain\Entity\PaymentRefund;

interface PaymentRefundRepositoryInterface
{
    public function save(PaymentRefund $paymentRefund): void;

    public function findById(string $id): ?PaymentRefund;

    public function findByRefundNumber(string $refundNumber): ?PaymentRefund;

    /**
     * @return PaymentRefund[]
     */
    public function findByPaymentId(string $paymentId): array;
}
