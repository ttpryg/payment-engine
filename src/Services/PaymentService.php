<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Services;

use DateTimeImmutable;
use PDO;
use Psr\EventDispatcher\EventDispatcherInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentAttemptRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentHistoryRepositoryInterface;
use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Entities\PaymentHistory;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\Enums\PaymentHistoryAction;
use Ttpryg\PaymentEngine\Enums\PaymentMethod;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\Events\PaymentCreatedEvent;
use Ttpryg\PaymentEngine\Exceptions\PaymentNotFoundException;
use Ttpryg\PaymentEngine\Gateways\GatewayManager;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;
use Ttpryg\PaymentEngine\ValueObjects\PaymentNumber;

class PaymentService
{
    public function __construct(
        private readonly PaymentRepositoryInterface $paymentRepo,
        private readonly PaymentAttemptRepositoryInterface $attemptRepo,
        private readonly PaymentHistoryRepositoryInterface $historyRepo,
        private readonly ?GatewayManager $gatewayManager = null,
        private readonly ?PaymentStatusService $statusService = null,
        private readonly ?EventDispatcherInterface $eventDispatcher = null,
        private readonly ?PDO $pdo = null
    ) {}

    public function createPayment(
        string $id,
        PayableReference $payable,
        Money|int $amount,
        PaymentMethod|string $method = PaymentMethod::BANK_TRANSFER,
        string $gatewayProvider = 'manual',
        ?PayerReference $payer = null,
        Money|int $fee = 0,
        ?string $paymentNumber = null,
        ?DateTimeImmutable $expiresAt = null,
        ?array $metadata = null,
        ?string $actorType = null,
        ?string $actorId = null
    ): Payment {
        $resolvedNumber = $paymentNumber ?? (string) PaymentNumber::generate();

        $payment = new Payment(
            id: $id,
            paymentNumber: $resolvedNumber,
            payable: $payable,
            payer: $payer,
            method: $method,
            gatewayProvider: $gatewayProvider,
            status: PaymentStatus::PENDING,
            amount: $amount,
            fee: $fee,
            expiresAt: $expiresAt,
            metadata: $metadata
        );

        $attempt = null;
        if ($this->gatewayManager instanceof GatewayManager && $this->gatewayManager->has($gatewayProvider)) {
            $gateway = $this->gatewayManager->get($gatewayProvider);
            $response = $gateway->createTransaction($payment);

            $attempt = new PaymentAttempt(
                id: 'att-'.bin2hex(random_bytes(8)),
                paymentId: $payment->id,
                gatewayProvider: $gatewayProvider,
                transactionReference: $response->transactionReference,
                status: $response->isSuccessful ? AttemptStatus::PENDING : AttemptStatus::FAILED,
                amount: $payment->totalAmount,
                rawRequest: ['gateway' => $gatewayProvider],
                rawResponse: $response->rawResponse
            );

            if ($response->metadata !== null) {
                $payment->metadata = array_merge($payment->metadata ?? [], $response->metadata);
            }
            if ($response->redirectUrl !== null) {
                $payment->metadata['redirect_url'] = $response->redirectUrl;
            }
            if ($response->paymentCode !== null) {
                $payment->metadata['payment_code'] = $response->paymentCode;
            }
        }

        $history = new PaymentHistory(
            id: 'pay-hist-'.bin2hex(random_bytes(8)),
            paymentId: $payment->id,
            action: PaymentHistoryAction::PAYMENT_CREATED,
            toStatus: PaymentStatus::PENDING,
            actorType: $actorType,
            actorId: $actorId,
            note: "Payment created for payable {$payable->type}:{$payable->id}",
            metadata: ['gateway_provider' => $gatewayProvider]
        );

        $this->executeInTransaction(function () use ($payment, $attempt, $history): void {
            $this->paymentRepo->save($payment);
            if ($attempt instanceof PaymentAttempt) {
                $this->attemptRepo->save($attempt);
            }
            $this->historyRepo->save($history);
        });

        if ($this->eventDispatcher instanceof EventDispatcherInterface) {
            $this->eventDispatcher->dispatch(new PaymentCreatedEvent($payment));
        }

        return $payment;
    }

    public function getPayment(string $id): ?Payment
    {
        return $this->paymentRepo->findById($id);
    }

    public function getPaymentByNumber(string $paymentNumber): ?Payment
    {
        return $this->paymentRepo->findByPaymentNumber($paymentNumber);
    }

    /**
     * @return Payment[]
     */
    public function getPaymentsByPayable(string $type, string $id): array
    {
        return $this->paymentRepo->findByPayable($type, $id);
    }

    /**
     * @return Payment[]
     */
    public function getPaymentsByPayer(string $type, string $id): array
    {
        return $this->paymentRepo->findByPayer($type, $id);
    }

    public function cancelPayment(string $paymentId, ?string $actorType = null, ?string $actorId = null, ?string $reason = null): Payment
    {
        if ($this->statusService instanceof PaymentStatusService) {
            return $this->statusService->cancel($paymentId, $reason, $actorType, $actorId);
        }

        $payment = $this->paymentRepo->findById($paymentId);
        if (! $payment instanceof Payment) {
            throw PaymentNotFoundException::forId($paymentId);
        }

        $fromStatus = $payment->status;
        $payment->status = PaymentStatus::CANCELLED;
        $payment->updatedAt = new DateTimeImmutable;
        $this->paymentRepo->save($payment);

        $history = new PaymentHistory(
            id: 'pay-hist-'.bin2hex(random_bytes(8)),
            paymentId: $payment->id,
            action: PaymentHistoryAction::STATUS_CHANGED,
            fromStatus: $fromStatus,
            toStatus: PaymentStatus::CANCELLED,
            actorType: $actorType,
            actorId: $actorId,
            note: $reason
        );
        $this->historyRepo->save($history);

        return $payment;
    }

    private function executeInTransaction(callable $callback): void
    {
        if ($this->pdo instanceof PDO && ! $this->pdo->inTransaction()) {
            $this->pdo->beginTransaction();
            try {
                $callback();
                $this->pdo->commit();
            } catch (\Throwable $e) {
                $this->pdo->rollBack();
                throw $e;
            }
        } else {
            $callback();
        }
    }
}
