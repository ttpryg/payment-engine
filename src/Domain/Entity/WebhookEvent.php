<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Domain\Entity;

use DateTimeImmutable;

class WebhookEvent
{
    public DateTimeImmutable $createdAt;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function __construct(
        public readonly string $id,
        public readonly string $gatewayProvider,
        public ?string $eventId,
        public readonly string $payloadFingerprint,
        public readonly array $payload,
        public bool $isProcessed = false,
        public ?DateTimeImmutable $processedAt = null,
        ?DateTimeImmutable $createdAt = null
    ) {
        $this->createdAt = $createdAt ?? new DateTimeImmutable;
    }

    public function markAsProcessed(): void
    {
        $this->isProcessed = true;
        $this->processedAt = new DateTimeImmutable;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public static function generateFingerprint(array $payload): string
    {
        return hash('sha256', json_encode($payload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES));
    }
}
