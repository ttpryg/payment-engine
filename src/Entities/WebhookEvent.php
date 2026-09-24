<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Entities;

use DateTimeImmutable;

class WebhookEvent
{
    public function __construct(
        public readonly string $id,
        public readonly string $gatewayProvider,
        public ?string $eventId,
        public readonly string $payloadFingerprint,
        public readonly array $payload,
        public bool $isProcessed = false,
        public ?DateTimeImmutable $processedAt = null,
        public ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }

    public function markAsProcessed(): void
    {
        $this->isProcessed = true;
        $this->processedAt = new DateTimeImmutable();
    }

    public static function generateFingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
