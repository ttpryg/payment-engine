<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Database;

use PDO;
use Ttpryg\DatabaseMigrator\MigrationRunner;

/**
 * Thin convenience wrapper around the shared `ttpryg/database-migrator` runner.
 *
 * The module keeps its own tracker table so it can share a database with other
 * modules without migration-name collisions.
 */
class DatabaseMigrator
{
    private const TRACKER_TABLE = 'payment_engine_migrations';

    private readonly MigrationRunner $runner;

    public function __construct(PDO $pdo, ?string $migrationsPath = null)
    {
        $this->runner = new MigrationRunner(
            $pdo,
            $migrationsPath ?? __DIR__.'/../../database/migrations',
            self::TRACKER_TABLE
        );
    }

    /**
     * Apply every migration that has not been recorded yet.
     *
     * @return array<int, string> Names of the migrations executed in this run.
     */
    public function migrate(): array
    {
        return $this->runner->migrate();
    }

    /**
     * Revert the most recently applied migrations.
     *
     * @return array<int, string> Names of the reverted migrations, newest first.
     */
    public function rollback(int $steps = 1): array
    {
        return $this->runner->rollback($steps);
    }

    /**
     * @return array<int, string> Names of the migrations recorded in the tracker.
     */
    public function appliedMigrations(): array
    {
        return $this->runner->appliedMigrations();
    }
}
