<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Pdo;

use DateTimeImmutable;
use PDO;
use Ttpryg\PaymentEngine\Contracts\WebhookEventRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\WebhookEvent;

class PdoWebhookEventRepository implements WebhookEventRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(WebhookEvent $event): void
    {
        $existing = $this->findById($event->id);

        $sql = $existing instanceof WebhookEvent
            ? "UPDATE webhook_events SET gateway_provider = :gateway_provider, event_id = :event_id, payload_fingerprint = :payload_fingerprint, payload = :payload, is_processed = :is_processed, processed_at = :processed_at WHERE id = :id"
            : "INSERT INTO webhook_events (id, gateway_provider, event_id, payload_fingerprint, payload, is_processed, processed_at, created_at) VALUES (:id, :gateway_provider, :event_id, :payload_fingerprint, :payload, :is_processed, :processed_at, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $event->id,
            'gateway_provider' => $event->gatewayProvider,
            'event_id' => $event->eventId,
            'payload_fingerprint' => $event->payloadFingerprint,
            'payload' => json_encode($event->payload, JSON_THROW_ON_ERROR),
            'is_processed' => $event->isProcessed ? 1 : 0,
            'processed_at' => $event->processedAt?->format('Y-m-d H:i:s'),
            'created_at' => $event->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?WebhookEvent
    {
        $stmt = $this->pdo->prepare("SELECT * FROM webhook_events WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByFingerprint(string $gatewayProvider, string $fingerprint): ?WebhookEvent
    {
        $stmt = $this->pdo->prepare("SELECT * FROM webhook_events WHERE gateway_provider = :gateway_provider AND payload_fingerprint = :fingerprint LIMIT 1");
        $stmt->execute([
            'gateway_provider' => $gatewayProvider,
            'fingerprint' => $fingerprint,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByEventId(string $gatewayProvider, string $eventId): ?WebhookEvent
    {
        $stmt = $this->pdo->prepare("SELECT * FROM webhook_events WHERE gateway_provider = :gateway_provider AND event_id = :event_id LIMIT 1");
        $stmt->execute([
            'gateway_provider' => $gatewayProvider,
            'event_id' => $eventId,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    private function mapToEntity(array $row): WebhookEvent
    {
        return new WebhookEvent(
            id: (string) $row['id'],
            gatewayProvider: (string) $row['gateway_provider'],
            eventId: $row['event_id'] ?? null,
            payloadFingerprint: (string) $row['payload_fingerprint'],
            payload: !empty($row['payload']) ? json_decode((string) $row['payload'], true) : [],
            isProcessed: (bool) $row['is_processed'],
            processedAt: !empty($row['processed_at']) ? new DateTimeImmutable($row['processed_at']) : null,
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null
        );
    }
}
