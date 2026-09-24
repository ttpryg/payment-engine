<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Memory;

use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;

class MemoryPaymentRepository implements PaymentRepositoryInterface
{
    /** @var array<string, Payment> */
    private array $payments = [];

    public function save(Payment $payment): void
    {
        $this->payments[$payment->id] = $payment;
    }

    public function findById(string $id): ?Payment
    {
        return $this->payments[$id] ?? null;
    }

    public function findByPaymentNumber(string $paymentNumber): ?Payment
    {
        foreach ($this->payments as $payment) {
            if ($payment->paymentNumber->value === $paymentNumber) {
                return $payment;
            }
        }
        return null;
    }

    public function findByPayable(string $payableType, string $payableId): array
    {
        $result = [];
        foreach ($this->payments as $payment) {
            if ($payment->payable->type === $payableType && $payment->payable->id === $payableId) {
                $result[] = $payment;
            }
        }
        return $result;
    }

    public function findByPayer(string $payerType, string $payerId): array
    {
        $result = [];
        foreach ($this->payments as $payment) {
            if ($payment->payer !== null && $payment->payer->type === $payerType && $payment->payer->id === $payerId) {
                $result[] = $payment;
            }
        }
        return $result;
    }

    public function delete(string $id): bool
    {
        if (isset($this->payments[$id])) {
            unset($this->payments[$id]);
            return true;
        }
        return false;
    }
}
