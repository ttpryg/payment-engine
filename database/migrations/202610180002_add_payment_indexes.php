<?php

declare(strict_types=1);

use Ttpryg\DatabaseMigrator\Migration;

return new class extends Migration
{
    /**
     * @var array<int, array{table: string, name: string, columns: string, unique?: bool}>
     */
    private const INDEXES = [
        ['table' => 'payments', 'name' => 'idx_payments_payable', 'columns' => 'payable_type, payable_id'],
        ['table' => 'payments', 'name' => 'idx_payments_payer', 'columns' => 'payer_type, payer_id'],
        ['table' => 'payments', 'name' => 'idx_payments_status', 'columns' => 'status'],
        ['table' => 'payments', 'name' => 'idx_payments_created_at', 'columns' => 'created_at'],
        ['table' => 'payment_attempts', 'name' => 'idx_payment_attempts_payment_id', 'columns' => 'payment_id'],
        ['table' => 'payment_attempts', 'name' => 'idx_attempts_tx_ref', 'columns' => 'gateway_provider, transaction_reference'],
        ['table' => 'payment_refunds', 'name' => 'idx_payment_refunds_payment_id', 'columns' => 'payment_id'],
        ['table' => 'payment_histories', 'name' => 'idx_payment_histories_payment_id', 'columns' => 'payment_id'],
        ['table' => 'webhook_events', 'name' => 'uq_webhook_fingerprint', 'columns' => 'gateway_provider, payload_fingerprint', 'unique' => true],
        ['table' => 'webhook_events', 'name' => 'uq_webhook_event_id', 'columns' => 'gateway_provider, event_id', 'unique' => true],
    ];

    public function up(PDO $pdo): void
    {
        foreach (self::INDEXES as $index) {
            $this->createIndex($pdo, $index);
        }
    }

    public function down(PDO $pdo): void
    {
        foreach (array_reverse(self::INDEXES) as $index) {
            $this->dropIndex($pdo, $index);
        }
    }

    /**
     * @param  array{table: string, name: string, columns: string, unique?: bool}  $index
     */
    private function createIndex(PDO $pdo, array $index): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql' && $this->mysqlIndexExists($pdo, $index['table'], $index['name'])) {
            return;
        }

        $unique = ($index['unique'] ?? false) ? 'UNIQUE ' : '';
        $ifNotExists = in_array($driver, ['sqlite', 'pgsql'], true) ? 'IF NOT EXISTS ' : '';

        $pdo->exec(
            "CREATE {$unique}INDEX {$ifNotExists}{$index['name']} ON {$index['table']} ({$index['columns']})"
        );
    }

    /**
     * @param  array{table: string, name: string, columns: string, unique?: bool}  $index
     */
    private function dropIndex(PDO $pdo, array $index): void
    {
        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);

        if ($driver === 'mysql') {
            if (! $this->mysqlIndexExists($pdo, $index['table'], $index['name'])) {
                return;
            }

            try {
                $pdo->exec("DROP INDEX {$index['name']} ON {$index['table']}");
            } catch (PDOException $exception) {
                // MySQL refuses to drop an index that InnoDB uses to enforce a
                // foreign key (error 1553). Keep it; the table migration's
                // down() drops the whole table, and on a standalone rollback
                // the index is still required by the constraint.
                if (($exception->errorInfo[1] ?? null) !== 1553) {
                    throw $exception;
                }
            }

            return;
        }

        $pdo->exec("DROP INDEX IF EXISTS {$index['name']}");
    }

    private function mysqlIndexExists(PDO $pdo, string $table, string $index): bool
    {
        $statement = $pdo->prepare(
            'SELECT COUNT(*) FROM information_schema.statistics
             WHERE table_schema = DATABASE() AND table_name = :table AND index_name = :index'
        );
        $statement->execute(['table' => $table, 'index' => $index]);

        return (int) $statement->fetchColumn() > 0;
    }
};
