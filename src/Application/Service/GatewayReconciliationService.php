<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Application\Service;

use DateTimeImmutable;
use PDO;
use Throwable;
use Ttpryg\PaymentEngine\Application\DTO\GatewayStatusResponse;
use Ttpryg\PaymentEngine\Concerns\ManagesTransactions;
use Ttpryg\PaymentEngine\Domain\Entity\Payment;
use Ttpryg\PaymentEngine\Domain\Entity\PaymentAttempt;
use Ttpryg\PaymentEngine\Domain\Enum\AttemptStatus;
use Ttpryg\PaymentEngine\Domain\Enum\PaymentStatus;
use Ttpryg\PaymentEngine\Domain\Exception\InvalidStatusTransitionException;
use Ttpryg\PaymentEngine\Domain\Exception\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentAttemptRepositoryInterface;
use Ttpryg\PaymentEngine\Domain\Repository\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Infrastructure\Gateway\GatewayManager;

class GatewayReconciliationService
{
    use ManagesTransactions;

    public function __construct(
        private readonly GatewayManager $gatewayManager,
        private readonly PaymentRepositoryInterface $paymentRepository,
        private readonly PaymentAttemptRepositoryInterface $paymentAttemptRepository,
        private readonly PaymentStatusService $paymentStatusService,
        private readonly ?PDO $pdo = null
    ) {}

    /**
     * Reconcile pending payments approaching expiration against their gateway.
     *
     * Because gateway webhooks can be dropped (network timeout, firewall), the
     * local record may remain `pending` even though the gateway already settled
     * the transaction. This method polls the gateway (`checkStatus()`) for each
     * candidate and syncs the local status. Gateway/network failures and
     * payments that moved on concurrently are skipped so a batch never aborts.
     *
     * @return Payment[] the payments whose local status was changed
     */
    public function reconcile(
        DateTimeImmutable $expiresBefore,
        string $actorType = 'system',
        ?string $actorId = 'reconciliation_worker'
    ): array {
        $reconciled = [];

        foreach ($this->paymentRepository->findPendingExpiringBefore($expiresBefore) as $payment) {
            $attempt = $this->latestAttemptWithReference($payment);
            if (! $attempt instanceof PaymentAttempt) {
                continue;
            }

            try {
                $gatewayStatusResponse = $this->gatewayManager
                    ->get($attempt->gatewayProvider)
                    ->checkStatus((string) $attempt->transactionReference);
            } catch (Throwable) {
                // Gateway unreachable or not registered; retry on the next run.
                continue;
            }

            $previousStatus = $payment->status;

            try {
                $updated = $this->runInTransaction($this->pdo, function () use ($attempt, $payment, $gatewayStatusResponse, $actorType, $actorId): Payment {
                    $this->syncAttempt($attempt, $gatewayStatusResponse);
                    $this->paymentAttemptRepository->save($attempt);

                    return $this->paymentStatusService->changeStatus(
                        payment: $payment,
                        targetStatus: $gatewayStatusResponse->mappedStatus,
                        actorType: $actorType,
                        actorId: $actorId,
                        note: "Reconciled with gateway [{$attempt->gatewayProvider}]",
                        metadata: [
                            'transaction_reference' => $attempt->transactionReference,
                            'raw_response' => $gatewayStatusResponse->rawResponse,
                        ],
                        paidAmount: $gatewayStatusResponse->paidAmount
                    );
                });
            } catch (InvalidStatusTransitionException|PaymentNotFoundException) {
                // The payment was completed/cancelled (or removed) concurrently.
                continue;
            }

            if ($gatewayStatusResponse->mappedStatus !== $previousStatus) {
                $reconciled[] = $updated;
            }
        }

        return $reconciled;
    }

    public function countReconcilable(DateTimeImmutable $expiresBefore): int
    {
        return count($this->paymentRepository->findPendingExpiringBefore($expiresBefore));
    }

    private function latestAttemptWithReference(Payment $payment): ?PaymentAttempt
    {
        $latest = null;

        foreach ($this->paymentAttemptRepository->findByPaymentId($payment->id) as $attempt) {
            if ($attempt->transactionReference === null) {
                continue;
            }

            if ($latest === null
                || $attempt->createdAt->getTimestamp() >= $latest->createdAt->getTimestamp()) {
                $latest = $attempt;
            }
        }

        return $latest;
    }

    private function syncAttempt(PaymentAttempt $paymentAttempt, GatewayStatusResponse $gatewayStatusResponse): void
    {
        if ($gatewayStatusResponse->mappedStatus === PaymentStatus::COMPLETED) {
            $paymentAttempt->status = AttemptStatus::SUCCESSFUL;
        } elseif (in_array($gatewayStatusResponse->mappedStatus, [PaymentStatus::FAILED, PaymentStatus::EXPIRED], true)) {
            $paymentAttempt->status = AttemptStatus::FAILED;
        }

        if ($gatewayStatusResponse->rawResponse !== null) {
            $paymentAttempt->rawResponse = $gatewayStatusResponse->rawResponse;
        }
    }
}
