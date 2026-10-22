<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Tests\Integration;

use PDO;
use PHPUnit\Framework\TestCase;
use Ttpryg\PaymentEngine\Infrastructure\Database\DatabaseMigrator;

class DatabaseMigratorTest extends TestCase
{
    private PDO $pdo;

    private DatabaseMigrator $migrator;

    protected function setUp(): void
    {
        $this->pdo = new PDO('sqlite::memory:');
        $this->pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);

        $this->migrator = new DatabaseMigrator($this->pdo);
    }

    public function test_migrate_creates_schema_and_records_migrations(): void
    {
        $executed = $this->migrator->migrate();

        $this->assertSame([
            '202610180001_create_payments_tables',
            '202610180002_add_payment_indexes',
        ], $executed);

        $this->assertTrue($this->tableExists('payments'));
        $this->assertTrue($this->tableExists('payment_attempts'));
        $this->assertTrue($this->tableExists('payment_refunds'));
        $this->assertTrue($this->tableExists('payment_histories'));
        $this->assertTrue($this->tableExists('webhook_events'));
        $this->assertTrue($this->indexExists('idx_payments_payable'));
        $this->assertTrue($this->indexExists('uq_webhook_fingerprint'));

        $this->assertSame($executed, $this->migrator->appliedMigrations());
    }

    public function test_migrate_is_idempotent(): void
    {
        $this->migrator->migrate();

        $this->assertSame([], $this->migrator->migrate());
        $this->assertCount(2, $this->migrator->appliedMigrations());
    }

    public function test_rollback_reverts_the_latest_migration_only(): void
    {
        $this->migrator->migrate();

        $rolledBack = $this->migrator->rollback();

        $this->assertSame(['202610180002_add_payment_indexes'], $rolledBack);
        $this->assertFalse($this->indexExists('idx_payments_payable'));
        $this->assertTrue($this->tableExists('payments'));
        $this->assertSame(['202610180001_create_payments_tables'], $this->migrator->appliedMigrations());
    }

    public function test_rollback_of_all_migrations_drops_the_tables(): void
    {
        $this->migrator->migrate();

        $rolledBack = $this->migrator->rollback(2);

        $this->assertSame([
            '202610180002_add_payment_indexes',
            '202610180001_create_payments_tables',
        ], $rolledBack);
        $this->assertFalse($this->tableExists('payments'));
        $this->assertFalse($this->tableExists('webhook_events'));
        $this->assertSame([], $this->migrator->appliedMigrations());
    }

    public function test_existing_database_gets_indexes_without_losing_data(): void
    {
        $this->createLegacyTables();
        $this->pdo->exec("INSERT INTO payments (id, payable_type, payable_id, status) VALUES ('p1', 'order', 'o1', 'pending')");

        $this->assertFalse($this->indexExists('idx_payments_payable'));

        $this->migrator->migrate();

        $this->assertTrue($this->indexExists('idx_payments_payable'));
        $this->assertTrue($this->indexExists('idx_payments_status'));
        $this->assertTrue($this->indexExists('idx_payment_attempts_payment_id'));
        $this->assertTrue($this->indexExists('uq_webhook_fingerprint'));
        $this->assertSame(1, $this->countRows('payments'));
    }

    private function createLegacyTables(): void
    {
        $this->pdo->exec(
            'CREATE TABLE payments (
                id VARCHAR(36) PRIMARY KEY,
                payable_type VARCHAR(50) NULL,
                payable_id VARCHAR(100) NULL,
                payer_type VARCHAR(50) NULL,
                payer_id VARCHAR(100) NULL,
                status VARCHAR(30) NULL,
                created_at DATETIME NULL
            );
            CREATE TABLE payment_attempts (
                id VARCHAR(36) PRIMARY KEY,
                payment_id VARCHAR(36) NULL,
                gateway_provider VARCHAR(50) NULL,
                transaction_reference VARCHAR(150) NULL
            );
            CREATE TABLE payment_refunds (
                id VARCHAR(36) PRIMARY KEY,
                payment_id VARCHAR(36) NULL
            );
            CREATE TABLE payment_histories (
                id VARCHAR(36) PRIMARY KEY,
                payment_id VARCHAR(36) NULL
            );
            CREATE TABLE webhook_events (
                id VARCHAR(36) PRIMARY KEY,
                gateway_provider VARCHAR(50) NULL,
                payload_fingerprint VARCHAR(64) NULL,
                event_id VARCHAR(150) NULL
            );'
        );
    }

    private function tableExists(string $table): bool
    {
        return $this->sqliteObjectExists('table', $table);
    }

    private function indexExists(string $index): bool
    {
        return $this->sqliteObjectExists('index', $index);
    }

    private function sqliteObjectExists(string $type, string $name): bool
    {
        $stmt = $this->pdo->prepare('SELECT COUNT(*) FROM sqlite_master WHERE type = :type AND name = :name');
        $stmt->execute(['type' => $type, 'name' => $name]);

        return (int) $stmt->fetchColumn() > 0;
    }

    private function countRows(string $table): int
    {
        return (int) $this->pdo->query("SELECT COUNT(*) FROM {$table}")->fetchColumn();
    }
}
