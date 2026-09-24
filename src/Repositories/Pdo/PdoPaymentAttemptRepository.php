<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Repositories\Pdo;

use DateTimeImmutable;
use PDO;
use Ttpryg\PaymentEngine\Contracts\PaymentAttemptRepositoryInterface;
use Ttpryg\PaymentEngine\Entities\PaymentAttempt;
use Ttpryg\PaymentEngine\Enums\AttemptStatus;
use Ttpryg\PaymentEngine\ValueObjects\Money;

class PdoPaymentAttemptRepository implements PaymentAttemptRepositoryInterface
{
    public function __construct(private readonly PDO $pdo) {}

    public function save(PaymentAttempt $attempt): void
    {
        $existing = $this->findById($attempt->id);

        $sql = $existing instanceof PaymentAttempt
            ? 'UPDATE payment_attempts SET payment_id = :payment_id, gateway_provider = :gateway_provider, transaction_reference = :transaction_reference, status = :status, amount = :amount, currency = :currency, raw_request = :raw_request, raw_response = :raw_response WHERE id = :id'
            : 'INSERT INTO payment_attempts (id, payment_id, gateway_provider, transaction_reference, status, amount, currency, raw_request, raw_response, created_at) VALUES (:id, :payment_id, :gateway_provider, :transaction_reference, :status, :amount, :currency, :raw_request, :raw_response, :created_at)';

        $stmt = $this->pdo->prepare($sql);
        $stmt->execute([
            'id' => $attempt->id,
            'payment_id' => $attempt->paymentId,
            'gateway_provider' => $attempt->gatewayProvider,
            'transaction_reference' => $attempt->transactionReference,
            'status' => $attempt->status->value,
            'amount' => $attempt->amount->amount,
            'currency' => $attempt->amount->currency,
            'raw_request' => $attempt->rawRequest !== null ? json_encode($attempt->rawRequest, JSON_THROW_ON_ERROR) : null,
            'raw_response' => $attempt->rawResponse !== null ? json_encode($attempt->rawResponse, JSON_THROW_ON_ERROR) : null,
            'created_at' => $attempt->createdAt->format('Y-m-d H:i:s'),
        ]);
    }

    public function findById(string $id): ?PaymentAttempt
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE id = :id');
        $stmt->execute(['id' => $id]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    public function findByPaymentId(string $paymentId): array
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE payment_id = :payment_id ORDER BY created_at ASC');
        $stmt->execute(['payment_id' => $paymentId]);
        $rows = $stmt->fetchAll(PDO::FETCH_ASSOC);

        return array_map($this->mapToEntity(...), $rows);
    }

    public function findByTransactionReference(string $gatewayProvider, string $transactionReference): ?PaymentAttempt
    {
        $stmt = $this->pdo->prepare('SELECT * FROM payment_attempts WHERE gateway_provider = :gateway_provider AND transaction_reference = :transaction_reference LIMIT 1');
        $stmt->execute([
            'gateway_provider' => $gatewayProvider,
            'transaction_reference' => $transactionReference,
        ]);
        $row = $stmt->fetch(PDO::FETCH_ASSOC);

        return $row ? $this->mapToEntity($row) : null;
    }

    private function mapToEntity(array $row): PaymentAttempt
    {
        return new PaymentAttempt(
            id: (string) $row['id'],
            paymentId: (string) $row['payment_id'],
            gatewayProvider: (string) $row['gateway_provider'],
            transactionReference: $row['transaction_reference'] ?? null,
            status: AttemptStatus::from((string) $row['status']),
            amount: new Money((int) $row['amount'], (string) $row['currency']),
            rawRequest: ! empty($row['raw_request']) ? json_decode((string) $row['raw_request'], true) : null,
            rawResponse: ! empty($row['raw_response']) ? json_decode((string) $row['raw_response'], true) : null,
            createdAt: ! empty($row['created_at']) ? new DateTimeImmutable($row['created_at']) : null
        );
    }
}
