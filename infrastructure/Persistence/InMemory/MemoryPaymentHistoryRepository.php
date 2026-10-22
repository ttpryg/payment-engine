<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Persistence\InMemory;

use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentHistoryRepositoryInterface;

class MemoryPaymentHistoryRepository implements PaymentHistoryRepositoryInterface
{
    /** @var array<string, PaymentHistory> */
    private array $histories = [];

    public function save(PaymentHistory $paymentHistory): void
    {
        $this->histories[$paymentHistory->id] = $paymentHistory;
    }

    public function findByPaymentId(string $paymentId): array
    {
        $result = [];
        foreach ($this->histories as $history) {
            if ($history->paymentId === $paymentId) {
                $result[] = $history;
            }
        }

        return $result;
    }
}
