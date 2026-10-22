<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Database;

use PDO;
use RuntimeException;
use Throwable;

/**
 * Entry point migrasi schema payment-engine.
 *
 * - Fresh install: menjalankan `database/schema.sql` (source of truth terkini,
 *   idempotent) lalu menandai seluruh migrasi incremental sebagai baseline.
 * - Database existing: menjalankan migrasi incremental yang belum diterapkan,
 *   tanpa menyentuh data lama (schema.sql memakai CREATE TABLE/INDEX IF NOT EXISTS).
 */
class DatabaseMigrator
{
    public function __construct(private readonly PDO $pdo) {}

    public function migrate(): void
    {
        // Deteksi sebelum exec: fresh install belum punya tabel payments.
        $isFreshInstall = ! $this->tableExists('payments');

        $sql = file_get_contents(__DIR__.'/../../database/schema.sql');
        if ($sql === false) {
            throw new RuntimeException('Unable to load payment-engine schema.sql file.');
        }

        $this->pdo->exec($sql);

        $runner = new MigrationRunner($this->pdo);

        if ($isFreshInstall) {
            // Fresh install: schema.sql sudah mencakup definisi terkini →
            // tandai semua migrasi sebagai baseline agar tidak dieksekusi ulang.
            $runner->markAllAsMigrated();

            return;
        }

        // Database existing: jalankan migrasi inkremental yang belum diterapkan.
        $runner->migrate();
    }

    private function tableExists(string $table): bool
    {
        try {
            $stmt = $this->pdo->query("SELECT 1 FROM {$table} LIMIT 1");
        } catch (Throwable) {
            return false;
        }

        return $stmt !== false;
    }
}
