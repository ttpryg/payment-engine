<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Memory;

use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;

class MemoryPaymentHistoryRepository implements PaymentHistoryRepositoryInterface
{
    /** @var array<string, PaymentHistory> */
    private array $histories = [];

    public function save(PaymentHistory $history): void
    {
        $this->histories[$history->id] = $history;
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
