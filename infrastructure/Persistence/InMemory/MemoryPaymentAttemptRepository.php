<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory;

use Ttpryg\PaymentEngine\Domain\Entity\PaymentAttempt;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentAttemptRepositoryInterface;

class MemoryPaymentAttemptRepository implements PaymentAttemptRepositoryInterface
{
    /** @var array<string, PaymentAttempt> */
    private array $attempts = [];

    public function save(PaymentAttempt $paymentAttempt): void
    {
        $this->attempts[$paymentAttempt->id] = $paymentAttempt;
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
