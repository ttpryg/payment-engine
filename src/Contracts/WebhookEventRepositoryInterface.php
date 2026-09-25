<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Contracts;

use Ttpryg\PaymentEngine\Entities\WebhookEvent;

interface WebhookEventRepositoryInterface
{
    public function save(WebhookEvent $webhookEvent): void;

    public function findById(string $id): ?WebhookEvent;

    public function findByFingerprint(string $gatewayProvider, string $fingerprint): ?WebhookEvent;

    public function findByEventId(string $gatewayProvider, string $eventId): ?WebhookEvent;
}
