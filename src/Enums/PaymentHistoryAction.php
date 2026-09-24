<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Enums;

enum PaymentHistoryAction: string
{
    case PAYMENT_CREATED = 'payment_created';
    case STATUS_CHANGED = 'status_changed';
    case ATTEMPT_CREATED = 'attempt_created';
    case WEBHOOK_RECEIVED = 'webhook_received';
    case REFUND_ISSUED = 'refund_issued';
}
