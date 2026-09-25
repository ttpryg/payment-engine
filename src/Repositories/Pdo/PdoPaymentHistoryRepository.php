<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Pdo;

use DateTimeImmutable;
use PDO;
use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;

class PdoPaymentHistoryRepository implements PaymentHistoryRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(PaymentHistory $paymentHistory): void
    {
        $sql = 'INSERT INTO payment_histories (id, payment_id, action, from_status, to_status, actor_type, actor_id, note, metadata, created_at) VALUES (:id, :payment_id, :action, :from_status, :to_status, :actor_type, :actor_id, :note, :metadata, :created_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $paymentHistory->id,
            'payment_id' => $paymentHistory->paymentId,
            'action' => $paymentHistory->action->value,
            'from_status' => $paymentHistory->fromStatus?->value,
            'to_status' => $paymentHistory->toStatus?->value,
            'actor_type' => $paymentHistory->actorType,
            'actor_id' => $paymentHistory->actorId,
            'note' => $paymentHistory->note,
            'metadata' => $paymentHistory->metadata !== null ? json_encode($paymentHistory->metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => $paymentHistory->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findByPaymentId(string $paymentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_histories WHERE payment_id = :payment_id ORDER BY created_at ASC');
        $stmt->execute(['payment_id' => $paymentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    private function mapToEntity(array $row): PaymentHistory
    {
        return new PaymentHistory(
            id: (string) $row['id'],
            paymentId: (string) $row['payment_id'],
            action: PaymentHistoryAction::from((string) $row['action']),
            fromStatus: ! empty($row['from_status']) ? PaymentStatus::from((string) $row['from_status']) : null,
            toStatus: ! empty($row['to_status']) ? PaymentStatus::from((string) $row['to_status']) : null,
            actorType: $row['actor_type'] ?? null,
            actorId: $row['actor_id'] ?? null,
            note: $row['note'] ?? null,
            metadata: ! empty($row['metadata']) ? json_decode((string) $row['metadata'], true) : null,
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null
        );
    }
}
