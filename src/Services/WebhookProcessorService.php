<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Services;

use Ttpryg\PaymentEngine\Contracts\PaymentAttemptRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\WebhookEventRepositoryInterface;
use Ttpryg\PaymentEngine\DTOs\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Entities\WebhookEvent;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Exceptions\GatewayException;
use Ttpryg\PaymentEngine\Exceptions\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;

class WebhookProcessorService
{
    public function __construct(
        private readonly GatewayManager $gatewayManager,
        private readonly WebhookEventRepositoryInterface $webhookRepo,
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentAttemptRepositoryInterface $attemptRepo,
        private readonly PaymentHistoryRepositoryInterface $historyRepo,
        private readonly PaymentStatusService $statusService
    ) {}

    public function process(string $gatewayProvider, array $payload, array $headers = []): WebhookVerificationResult
    {
        $gateway = $this->gatewayManager->get($gatewayProvider);
        $result = $gateway->verifyWebhook($payload, $headers);

        if (! $result->isValid) {
            throw GatewayException::failed($gatewayProvider, $result->failureReason ?? 'Webhook verification failed');
        }

        $fingerprint = $result->payloadFingerprint ?? WebhookEvent::generateFingerprint($payload);

        // 1. Idempotency Check: By Event ID
        if ($result->eventId !== null) {
            $existingByEventId = $this->webhookRepo->findByEventId($gatewayProvider, $result->eventId);
            if ($existingByEventId instanceof WebhookEvent && $existingByEventId->isProcessed) {
                return $result; // Duplicate detected: return early without side-effects
            }
        }

        // 2. Idempotency Check: By Payload Fingerprint
        $existingByFingerprint = $this->webhookRepo->findByFingerprint($gatewayProvider, $fingerprint);
        if ($existingByFingerprint instanceof WebhookEvent && $existingByFingerprint->isProcessed) {
            return $result; // Duplicate detected: return early without side-effects
        }

        // 3. Record WebhookEvent audit record
        $webhookEvent = new WebhookEvent(
            id: 'wh-'.bin2hex(random_bytes(8)),
            gatewayProvider: $gatewayProvider,
            eventId: $result->eventId,
            payloadFingerprint: $fingerprint,
            payload: $payload,
            isProcessed: false
        );
        $this->webhookRepo->save($webhookEvent);

        // 4. Resolve Payment & Attempt
        $payment = null;
        if ($result->transactionReference !== null) {
            $attempt = $this->attemptRepo->findByTransactionReference($gatewayProvider, $result->transactionReference);
            if ($attempt instanceof PaymentAttempt) {
                $payment = $this->paymentRepo->findById($attempt->paymentId);
                if ($result->mappedStatus === PaymentStatus::COMPLETED) {
                    $attempt->status = AttemptStatus::SUCCESSFUL;
                } elseif (in_array($result->mappedStatus, [PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
                    $attempt->status = AttemptStatus::FAILED;
                }
                $this->attemptRepo->save($attempt);
            }
        }

        if (! $payment instanceof Payment && isset($payload['payment_number'])) {
            $payment = $this->paymentRepo->findByPaymentNumber((string) $payload['payment_number']);
        }

        if (! $payment instanceof Payment && isset($payload['payment_id'])) {
            $payment = $this->paymentRepo->findById((string) $payload['payment_id']);
        }

        if (! $payment instanceof Payment) {
            throw PaymentNotFoundException::forPaymentNumber($result->transactionReference ?? 'unknown');
        }

        // 5. Update Status & Audit History
        if ($result->mappedStatus instanceof PaymentStatus) {
            $this->statusService->changeStatus(
                payment: $payment,
                targetStatus: $result->mappedStatus,
                actorType: 'gateway',
                actorId: $gatewayProvider,
                note: "Webhook received from [{$gatewayProvider}]",
                metadata: ['webhook_event_id' => $webhookEvent->id, 'transaction_reference' => $result->transactionReference],
                paidAmount: $result->paidAmount
            );
        }

        $webhookHistory = new PaymentHistory(
            id: 'pay-hist-'.bin2hex(random_bytes(8)),
            paymentId: $payment->id,
            action: PaymentHistoryAction::WEBHOOK_RECEIVED,
            toStatus: $payment->status,
            actorType: 'gateway',
            actorId: $gatewayProvider,
            note: 'Webhook event processed',
            metadata: ['fingerprint' => $fingerprint, 'event_id' => $result->eventId]
        );
        $this->historyRepo->save($webhookHistory);

        // 6. Mark WebhookEvent as processed
        $webhookEvent->markAsProcessed();
        $this->webhookRepo->save($webhookEvent);

        return $result;
    }
}
