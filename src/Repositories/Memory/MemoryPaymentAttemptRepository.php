<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Memory;

use Ttpryg\PaymentEngine\Contracts\PaymentAttemptRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;

class MemoryPaymentAttemptRepository implements PaymentAttemptRepositoryInterface
{
    /** @var array<string, PaymentAttempt> */
    private array $attempts = [];

    public function save(PaymentAttempt $attempt): void
    {
        $this->attempts[$attempt->id] = $attempt;
    }

    public function findById(string $id): ?PaymentAttempt
    {
        return $this->attempts[$id] ?? null;
    }

    public function findByPaymentId(string $paymentId): array
    {
        $result = [];
        foreach ($this->attempts as $attempt) {
            if ($attempt->paymentId === $paymentId) {
                $result[] = $attempt;
            }
        }

        return $result;
    }

    public function findByTransactionReference(string $gatewayProvider, string $transactionReference): ?PaymentAttempt
    {
        foreach ($this->attempts as $attempt) {
            if ($attempt->gatewayProvider === $gatewayProvider && $attempt->transactionReference === $transactionReference) {
                return $attempt;
            }
        }

        return null;
    }
}
