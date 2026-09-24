<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Pdo;

use DateTimeImmutable;
use PDO;
use Ttpryg\PaymentEngine\Contracts\PaymentRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\Payment;
use Ttpryg\PaymentEngine\Enums\PaymentMethod;
use Ttpryg\PaymentEngine\Enums\PaymentStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;
use Ttpryg\PaymentEngine\ValueObjects\PayableReference;
use Ttpryg\PaymentEngine\ValueObjects\PayerReference;
use Ttpryg\PaymentEngine\ValueObjects\PaymentNumber;

class PdoPaymentRepository implements PaymentRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(Payment $payment): void
    {
        $existing = $this->findById($payment->id);

        $sql = $existing instanceof Payment
            ? 'UPDATE payments SET payment_number = :payment_number, payable_type = :payable_type, payable_id = :payable_id, payer_type = :payer_type, payer_id = :payer_id, method = :method, gateway_provider = :gateway_provider, status = :status, currency = :currency, amount = :amount, fee = :fee, total_amount = :total_amount, paid_amount = :paid_amount, refunded_amount = :refunded_amount, expires_at = :expires_at, paid_at = :paid_at, metadata = :metadata, updated_at = :updated_at WHERE id = :id'
            : 'INSERT INTO payments (id, payment_number, payable_type, payable_id, payer_type, payer_id, method, gateway_provider, status, currency, amount, fee, total_amount, paid_amount, refunded_amount, expires_at, paid_at, metadata, created_at, updated_at) VALUES (:id, :payment_number, :payable_type, :payable_id, :payer_type, :payer_id, :method, :gateway_provider, :status, :currency, :amount, :fee, :total_amount, :paid_amount, :refunded_amount, :expires_at, :paid_at, :metadata, :created_at, :updated_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $payment->id,
            'payment_number' => $payment->paymentNumber->value,
            'payable_type' => $payment->payable->type,
            'payable_id' => $payment->payable->id,
            'payer_type' => $payment->payer?->type,
            'payer_id' => $payment->payer?->id,
            'method' => $payment->method->value,
            'gateway_provider' => $payment->gatewayProvider,
            'status' => $payment->status->value,
            'currency' => $payment->amount->currency,
            'amount' => $payment->amount->amount,
            'fee' => $payment->fee->amount,
            'total_amount' => $payment->totalAmount->amount,
            'paid_amount' => $payment->paidAmount->amount,
            'refunded_amount' => $payment->refundedAmount->amount,
            'expires_at' => $payment->expiresAt?->format('Y-m-d H:i:s'),
            'paid_at' => $payment->paidAt?->format('Y-m-d H:i:s'),
            'metadata' => $payment->metadata !== null ? json_encode($payment->metadata, JSON_THROW_ON_ERROR) : null,
            'created_at' => $payment->createdAt->format('Y-m-d H:i:s'),
            'updated_at' => $payment->updatedAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?Payment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByPaymentNumber(string $paymentNumber): ?Payment
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE payment_number = :payment_number');
        $stmt->execute(['payment_number' => $paymentNumber]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByPayable(string $payableType, string $payableId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE payable_type = :payable_type AND payable_id = :payable_id ORDER BY created_at DESC');
        $stmt->execute(['payable_type' => $payableType, 'payable_id' => $payableId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    public function findByPayer(string $payerType, string $payerId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payments WHERE payer_type = :payer_type AND payer_id = :payer_id ORDER BY created_at DESC');
        $stmt->execute(['payer_type' => $payerType, 'payer_id' => $payerId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    public function delete(string $id): bool
    {
        $stmt = $this->pdo->prepare('DELETE FROM payments WHERE id = :id');

        return $stmt->execute(['id' => $id]);
    }

    private function mapToEntity(array $row): Payment
    {
        $currency = (string) $row['currency'];
        $payer = ! empty($row['payer_type']) && ! empty($row['payer_id'])
            ? new PayerReference((string) $row['payer_type'], (string) $row['payer_id'])
            : null;

        return new Payment(
            id: (string) $row['id'],
            paymentNumber: new PaymentNumber((string) $row['payment_number']),
            payable: new PayableReference((string) $row['payable_type'], (string) $row['payable_id']),
            payer: $payer,
            method: PaymentMethod::from((string) $row['method']),
            gatewayProvider: (string) $row['gateway_provider'],
            status: PaymentStatus::from((string) $row['status']),
            amount: new Money((int) $row['amount'], $currency),
            fee: new Money((int) $row['fee'], $currency),
            totalAmount: new Money((int) $row['total_amount'], $currency),
            paidAmount: new Money((int) $row['paid_amount'], $currency),
            refundedAmount: new Money((int) $row['refunded_amount'], $currency),
            expiresAt: ! empty($row['expires_at']) ? new DateTimeImmutable($row['expires_at']) : null,
            paidAt: ! empty($row['paid_at']) ? new DateTimeImmutable($row['paid_at']) : null,
            metadata: ! empty($row['metadata']) ? json_decode((string) $row['metadata'], true) : null,
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null,
            updatedAt: ! empty($row['updated_at']) ? new DateTimeImmutable($row['updated_at']) : null
        );
    }
}
