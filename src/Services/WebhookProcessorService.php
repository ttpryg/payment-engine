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
        private readonly WebhookEventRepositoryInterface $webhookEventRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentAttemptRepositoryInterface $paymentAttemptRepository,
        private readonly PaymentHistoryRepositoryInterface $paymentHistoryRepository,
        private readonly PaymentStatusService $paymentStatusService
    ) {}

    public function process(string $gatewayProvider, array $payload, array $headers = []): WebhookVerificationResult
    {
        $paymentGateway = $this->gatewayManager->get($gatewayProvider);
        $webhookVerificationResult = $paymentGateway->verifyWebhook($payload, $headers);

        if (! $webhookVerificationResult->isValid) {
            throw GatewayException::failed($gatewayProvider, $webhookVerificationResult->failureReason ?? 'Webhook verification failed');
        }

        $fingerprint = $webhookVerificationResult->payloadFingerprint ?? WebhookEvent::generateFingerprint($payload);

        // 1. Idempotency Check: By Event ID
        if ($webhookVerificationResult->eventId !== null) {
            $existingByEventId = $this->webhookEventRepository->findByEventId($gatewayProvider, $webhookVerificationResult->eventId);
            if ($existingByEventId instanceof WebhookEvent && $existingByEventId->isProcessed) {
                return $webhookVerificationResult; // Duplicate detected: return early without side-effects
            }
        }

        // 2. Idempotency Check: By Payload Fingerprint
        $existingByFingerprint = $this->webhookEventRepository->findByFingerprint($gatewayProvider, $fingerprint);
        if ($existingByFingerprint instanceof WebhookEvent && $existingByFingerprint->isProcessed) {
            return $webhookVerificationResult; // Duplicate detected: return early without side-effects
        }

        // 3. Record WebhookEvent audit record
        $webhookEvent = new WebhookEvent(
            id: 'wh-'.bin2hex(random_bytes(8)),
            gatewayProvider: $gatewayProvider,
            eventId: $webhookVerificationResult->eventId,
            payloadFingerprint: $fingerprint,
            payload: $payload,
            isProcessed: false
        );
        $this->webhookEventRepository->save($webhookEvent);

        // 4. Resolve Payment & Attempt
        $payment = null;
        if ($webhookVerificationResult->transactionReference !== null) {
            $attempt = $this->paymentAttemptRepository->findByTransactionReference($gatewayProvider, $webhookVerificationResult->transactionReference);
            if ($attempt instanceof PaymentAttempt) {
                $payment = $this->paymentRepository->findById($attempt->paymentId);
                if ($webhookVerificationResult->mappedStatus === PaymentStatus::COMPLETED) {
                    $attempt->status = AttemptStatus::SUCCESSFUL;
                } elseif (in_array($webhookVerificationResult->mappedStatus, [PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
                    $attempt->status = AttemptStatus::FAILED;
                }
                $this->paymentAttemptRepository->save($attempt);
            }
        }

        if (! $payment instanceof Payment && isset($payload['payment_number'])) {
            $payment = $this->paymentRepository->findByPaymentNumber((string) $payload['payment_number']);
        }

        if (! $payment instanceof Payment && isset($payload['payment_id'])) {
            $payment = $this->paymentRepository->findById((string) $payload['payment_id']);
        }

        if (! $payment instanceof Payment) {
            throw PaymentNotFoundException::forPaymentNumber($webhookVerificationResult->transactionReference ?? 'unknown');
        }

        // 5. Update Status & Audit History
        if ($webhookVerificationResult->mappedStatus instanceof PaymentStatus) {
            $this->paymentStatusService->changeStatus(
                payment: $payment,
                actorType: 'gateway',
                actorId: $gatewayProvider,
                note: "Webhook received from [{$gatewayProvider}]",
                metadata: ['webhook_event_id' => $webhookEvent->id, 'transaction_reference' => $webhookVerificationResult->transactionReference],
                targetStatus: $webhookVerificationResult->mappedStatus,
                paidAmount: $webhookVerificationResult->paidAmount
            );
        }

        $paymentHistory = new PaymentHistory(
            id: 'pay-hist-'.bin2hex(random_bytes(8)),
            paymentId: $payment->id,
            action: PaymentHistoryAction::WEBHOOK_RECEIVED,
            toStatus: $payment->status,
            actorType: 'gateway',
            actorId: $gatewayProvider,
            note: 'Webhook event processed',
            metadata: ['fingerprint' => $fingerprint, 'event_id' => $webhookVerificationResult->eventId]
        );
        $this->paymentHistoryRepository->save($paymentHistory);

        // 6. Mark WebhookEvent as processed
        $webhookEvent->markAsProcessed();
        $this->webhookEventRepository->save($webhookEvent);

        return $webhookVerificationResult;
    }
}
