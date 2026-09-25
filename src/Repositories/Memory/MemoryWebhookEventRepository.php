<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Memory;

use Ttpryg\PaymentEngine\Contracts\WebhookEventRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\WebhookEvent;

class MemoryWebhookEventRepository implements WebhookEventRepositoryInterface
{
    /** @var array<string, WebhookEvent> */
    private array $events = [];

    public function save(WebhookEvent $webhookEvent): void
    {
        $this->events[$webhookEvent->id] = $webhookEvent;
    }

    public function findById(string $id): ?WebhookEvent
    {
        return $this->events[$id] ?? null;
    }

    public function findByFingerprint(string $gatewayProvider, string $fingerprint): ?WebhookEvent
    {
        foreach ($this->events as $event) {
            if ($event->gatewayProvider === $gatewayProvider && $event->payloadFingerprint === $fingerprint) {
                return $event;
            }
        }

        return null;
    }

    public function findByEventId(string $gatewayProvider, string $eventId): ?WebhookEvent
    {
        foreach ($this->events as $event) {
            if ($event->gatewayProvider === $gatewayProvider && $event->eventId === $eventId) {
                return $event;
            }
        }

        return null;
    }
}
