<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Entities\PaymentRefund;

interface PaymentRefundRepositoryInterface
{
    public function save(PaymentRefund $refund): void;

    public function findById(string $id): ?PaymentRefund;

    public function findByRefundNumber(string $refundNumber): ?PaymentRefund;

    /**
     * @return PaymentRefund[]
     */
    public function findByPaymentId(string $paymentId): array;
}
