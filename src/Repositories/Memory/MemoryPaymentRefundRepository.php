<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Memory;

use Ttpryg\PaymentEngine\Contracts\PaymentRefundRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\PaymentRefund;

class MemoryPaymentRefundRepository implements PaymentRefundRepositoryInterface
{
    /** @var array<string, PaymentRefund> */
    private array $refunds = [];

    public function save(PaymentRefund $refund): void
    {
        $this->refunds[$refund->id] = $refund;
    }

    public function findById(string $id): ?PaymentRefund
    {
        return $this->refunds[$id] ?? null;
    }

    public function findByRefundNumber(string $refundNumber): ?PaymentRefund
    {
        foreach ($this->refunds as $refund) {
            if ($refund->refundNumber === $refundNumber) {
                return $refund;
            }
        }

        return null;
    }

    public function findByPaymentId(string $paymentId): array
    {
        $result = [];
        foreach ($this->refunds as $refund) {
            if ($refund->paymentId === $paymentId) {
                $result[] = $refund;
            }
        }

        return $result;
    }
}
