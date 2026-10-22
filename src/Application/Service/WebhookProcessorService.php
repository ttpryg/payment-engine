<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\Service;

use PDO;
use Ttpryg\PaymentEngine\Application\DTO\WebhookVerificationResult;
use Ttpryg\PaymentEngine\Concerns\ManagesTransactions;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentAttempt;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentHistory;
use Ttpryg\PaymentEngine\Domain\Entity\WebhookEvent;
use Ttpryg\PaymentEngine\Domain\Enum\AttemptStatus;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Exception\DuplicateWebhookException;
use Ttpryg\PaymentEngine\Domain\Exception\GatewayException;
use Ttpryg\PaymentEngine\Domain\Exception\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentAttemptRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\WebhookEventRepositoryInterface;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;

class WebhookProcessorService
{
    use ManagesTransactions;

    public function __construct(
        private readonly GatewayManager $gatewayManager,
        private readonly WebhookEventRepositoryInterface $webhookEventRepository,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentAttemptRepositoryInterface $paymentAttemptRepository,
        private readonly PaymentHistoryRepositoryInterface $paymentHistoryRepository,
        private readonly PaymentStatusService $paymentStatusService,
        private readonly ?PDO $pdo = null
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

        try {
            return $this->runInTransaction(
                $this->pdo,
                fn (): WebhookVerificationResult => $this->applyVerifiedWebhook($gatewayProvider, $fingerprint, $webhookVerificationResult, $payload)
            );
        } catch (DuplicateWebhookException) {
            // A concurrent delivery already recorded this webhook (TOCTOU race).
            // The database unique constraint is authoritative: the transaction is
            // rolled back and the delivery is treated as an idempotent no-op.
            return $webhookVerificationResult;
        }
    }

    private function applyVerifiedWebhook(
        string $gatewayProvider,
        string $fingerprint,
        WebhookVerificationResult $webhookVerificationResult,
        array $payload
    ): WebhookVerificationResult {
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
            $payment = $this->paymentStatusService->changeStatus(
                payment: $payment,
                targetStatus: $webhookVerificationResult->mappedStatus,
                actorType: 'gateway',
                actorId: $gatewayProvider,
                note: "Webhook received from [{$gatewayProvider}]",
                metadata: ['webhook_event_id' => $webhookEvent->id, 'transaction_reference' => $webhookVerificationResult->transactionReference],
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
