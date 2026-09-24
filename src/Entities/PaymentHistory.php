<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Entities;

use DateTimeImmutable;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;

class PaymentHistory
{
    public readonly PaymentHistoryAction $action;
    public readonly ?PaymentStatus $fromStatus;
    public readonly ?PaymentStatus $toStatus;

    public function __construct(
        public readonly string $id,
        public readonly string $paymentId,
        PaymentHistoryAction|string $action,
        PaymentStatus|string|null $fromStatus = null,
        PaymentStatus|string|null $toStatus = null,
        public ?string $actorType = null,
        public ?string $actorId = null,
        public ?string $note = null,
        public ?array $metadata = null,
        public ?DateTimeImmutable $createdAt = null
    ) {
        $this->action = is_string($action) ? PaymentHistoryAction::from($action) : $action;
        $this->fromStatus = is_string($fromStatus) ? PaymentStatus::from($fromStatus) : $fromStatus;
        $this->toStatus = is_string($toStatus) ? PaymentStatus::from($toStatus) : $toStatus;
        $this->createdAt = $createdAt ?? new DateTimeImmutable();
    }
}
