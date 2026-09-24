<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Pdo;

use DateTimeImmutable;
use PDO;
use Ttpryg\PaymentEngine\Contracts\PaymentRefundRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\PaymentRefund;
use Ttpryg\PaymentEngine\Enums\RefundStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class PdoPaymentRefundRepository implements PaymentRefundRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(PaymentRefund $refund): void
    {
        $existing = $this->findById($refund->id);

        $sql = $existing instanceof PaymentRefund
            ? "UPDATE payment_refunds SET payment_id = :payment_id, refund_number = :refund_number, amount = :amount, currency = :currency, reason = :reason, status = :status, gateway_refund_id = :gateway_refund_id, actor_type = :actor_type, actor_id = :actor_id WHERE id = :id"
            : "INSERT INTO payment_refunds (id, payment_id, refund_number, amount, currency, reason, status, gateway_refund_id, actor_type, actor_id, created_at) VALUES (:id, :payment_id, :refund_number, :amount, :currency, :reason, :status, :gateway_refund_id, :actor_type, :actor_id, :created_at)";

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $refund->id,
            'payment_id' => $refund->paymentId,
            'refund_number' => $refund->refundNumber,
            'amount' => $refund->amount->amount,
            'currency' => $refund->amount->currency,
            'reason' => $refund->reason,
            'status' => $refund->status->value,
            'gateway_refund_id' => $refund->gatewayRefundId,
            'actor_type' => $refund->actorType,
            'actor_id' => $refund->actorId,
            'created_at' => $refund->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?PaymentRefund
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payment_refunds WHERE id = :id");
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByRefundNumber(string $refundNumber): ?PaymentRefund
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payment_refunds WHERE refund_number = :refund_number");
        $stmt->execute(['refund_number' => $refundNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByPaymentId(string $paymentId): array
    {
        $stmt = $this->pdo->prepare("SELECT * FROM payment_refunds WHERE payment_id = :payment_id ORDER BY created_at ASC");
        $stmt->execute(['payment_id' => $paymentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    private function mapToEntity(array $row): PaymentRefund
    {
        return new PaymentRefund(
            id: (string) $row['id'],
            paymentId: (string) $row['payment_id'],
            refundNumber: (string) $row['refund_number'],
            amount: new Money((int) $row['amount'], (string) $row['currency']),
            reason: $row['reason'] ?? null,
            status: RefundStatus::from((string) $row['status']),
            gatewayRefundId: $row['gateway_refund_id'] ?? null,
            actorType: $row['actor_type'] ?? null,
            actorId: $row['actor_id'] ?? null,
            createdAt: !empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null
        );
    }
}
