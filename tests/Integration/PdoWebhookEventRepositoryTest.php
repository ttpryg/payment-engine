<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Domain\Entity\WebhookEvent;
use Ttpryg\PaymentEngine\Domain\Exception\DuplicateWebhookException;
use Ttpryg\PaymentEngine\Infrastructure\Persistence\Pdo\PdoWebhookEventRepository;

class PdoWebhookEventRepositoryTest extends PdoRepositoryTestCase
{
    private PdoWebhookEventRepository $repository;

    protected function setUp(): void
    {
        parent::setUp();

        $this->repository = new PdoWebhookEventRepository($this->pdo);
    }

    public function test_save_find_and_mark_webhook_as_processed(): void
    {
        $event = new WebhookEvent(
            id: 'wh-1',
            gatewayProvider: 'mock',
            eventId: 'evt-1',
            payloadFingerprint: 'fingerprint-1',
            payload: ['status' => 'settled', 'amount' => 100000],
            createdAt: new DateTimeImmutable('2026-10-22 13:00:00')
        );

        $this->repository->save($event);

        $found = $this->repository->findById('wh-1');
        $this->assertNotNull($found);
        $this->assertSame('mock', $found->gatewayProvider);
        $this->assertSame('evt-1', $found->eventId);
        $this->assertSame('fingerprint-1', $found->payloadFingerprint);
        $this->assertSame(['status' => 'settled', 'amount' => 100000], $found->payload);
        $this->assertFalse($found->isProcessed);
        $this->assertNull($found->processedAt);
        $this->assertSame('2026-10-22 13:00:00', $found->createdAt->format('Y-m-d H:i:s'));

        $this->assertSame('wh-1', $this->repository->findByFingerprint('mock', 'fingerprint-1')?->id);
        $this->assertSame('wh-1', $this->repository->findByEventId('mock', 'evt-1')?->id);
        $this->assertNull($this->repository->findByFingerprint('other', 'fingerprint-1'));
        $this->assertNull($this->repository->findByEventId('other', 'evt-1'));
        $this->assertNull($this->repository->findById('missing'));

        $found->markAsProcessed();
        $this->repository->save($found);

        $processed = $this->repository->findById('wh-1');
        $this->assertNotNull($processed);
        $this->assertTrue($processed->isProcessed);
        $this->assertNotNull($processed->processedAt);
        $this->assertSame('2026-10-22 13:00:00', $processed->createdAt->format('Y-m-d H:i:s'));
    }

    public function test_duplicate_fingerprint_is_rejected(): void
    {
        $this->repository->save(new WebhookEvent(
            id: 'wh-1',
            gatewayProvider: 'mock',
            eventId: 'evt-1',
            payloadFingerprint: 'fingerprint-duplicate',
            payload: ['status' => 'settled']
        ));

        $this->expectException(DuplicateWebhookException::class);

        $this->repository->save(new WebhookEvent(
            id: 'wh-2',
            gatewayProvider: 'mock',
            eventId: 'evt-2',
            payloadFingerprint: 'fingerprint-duplicate',
            payload: ['status' => 'settled']
        ));
    }

    public function test_duplicate_event_id_is_rejected(): void
    {
        $this->repository->save(new WebhookEvent(
            id: 'wh-1',
            gatewayProvider: 'mock',
            eventId: 'evt-duplicate',
            payloadFingerprint: 'fingerprint-1',
            payload: ['status' => 'settled']
        ));

        $this->expectException(DuplicateWebhookException::class);

        $this->repository->save(new WebhookEvent(
            id: 'wh-2',
            gatewayProvider: 'mock',
            eventId: 'evt-duplicate',
            payloadFingerprint: 'fingerprint-2',
            payload: ['status' => 'settled']
        ));
    }
}
