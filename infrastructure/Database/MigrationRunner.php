<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Infrastructure\Database;

use DateTimeImmutable;
use PDO;
use RuntimeException;

/**
 * Runner migrasi skema incremental dengan tracker table.
 *
 * Setiap file `*.sql` di dalam direktori migrations dijalankan berdasarkan nama
 * (urut alfabetis) dan dicatat ke tabel tracker setelah berhasil, sehingga aman
 * dijalankan berulang tanpa merusak data production.
 *
 * Catatan MySQL: DDL bersifat auto-commit, jadi kegagalan di tengah satu file
 * migrasi tidak dapat di-rollback. File migrasi sebaiknya ditulis idempotent
 * (mis. CREATE TABLE IF NOT EXISTS / ALTER yang tertolak dengan aman).
 */
class MigrationRunner
{
    private const TRACKER_TABLE = 'payment_engine_migrations';

    public function __construct(
        private readonly PDO $pdo,
        private readonly string $migrationsPath = __DIR__.'/../../database/migrations'
    ) {}

    /**
     * Jalankan semua migrasi yang belum diterapkan.
     *
     * @return array<int, string> Daftar nama migrasi yang dieksekusi pada run ini.
     *
     * @throws \Throwable
     */
    public function migrate(): array
    {
        $applied = $this->appliedMigrations(); // juga memastikan tabel tracker ada
        $executed = [];

        foreach ($this->discoverMigrations() as $name => $path) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $sql = file_get_contents($path);
            if ($sql === false) {
                throw new RuntimeException("Unable to read migration file '{$path}'.");
            }

            // Tracker hanya dicatat setelah eksekusi sukses; jika gagal,
            // migrasi akan diulang otomatis pada run berikutnya.
            $this->pdo->exec($sql);
            $this->record($name);

            $executed[] = $name;
        }

        return $executed;
    }

    /**
     * Tandai semua migrasi tersedia sebagai sudah diterapkan tanpa mengeksekusinya.
     *
     * Dipakai oleh DatabaseMigrator::migrate() pada fresh install, di mana
     * schema.sql sudah mencakup definisi terkini.
     *
     * @return array<int, string> Daftar nama migrasi yang dicatat sebagai baseline.
     */
    public function markAllAsMigrated(): array
    {
        $applied = $this->appliedMigrations(); // juga memastikan tabel tracker ada
        $recorded = [];

        foreach (array_keys($this->discoverMigrations()) as $name) {
            if (in_array($name, $applied, true)) {
                continue;
            }

            $this->record($name);
            $recorded[] = $name;
        }

        return $recorded;
    }

    /**
     * @return array<int, string> Daftar nama migrasi yang sudah tercatat di tracker.
     */
    public function appliedMigrations(): array
    {
        $this->ensureTrackerTable();

        $stmt = $this->pdo->prepare('SELECT migration FROM '.self::TRACKER_TABLE.' ORDER BY migration');
        $stmt->execute();

        $migrations = [];
        while (($row = $stmt->fetchColumn()) !== false) {
            $migrations[] = (string) $row;
        }

        return $migrations;
    }

    private function ensureTrackerTable(): void
    {
        $this->pdo->exec(
            'CREATE TABLE IF NOT EXISTS '.self::TRACKER_TABLE.' (
                migration VARCHAR(191) NOT NULL PRIMARY KEY,
                applied_at TIMESTAMP NULL
            )'
        );
    }

    private function record(string $name): void
    {
        $stmt = $this->pdo->prepare(
            'INSERT INTO '.self::TRACKER_TABLE.' (migration, applied_at) VALUES (:migration, :applied_at)'
        );
        $stmt->execute([
            'migration' => $name,
            'applied_at' => (new DateTimeImmutable)->format('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @return array<string, string> Map nama migrasi => path file, terurut alfabetis (asc).
     */
    private function discoverMigrations(): array
    {
        if (! is_dir($this->migrationsPath)) {
            return [];
        }

        $files = glob($this->migrationsPath.'/*.sql');
        if ($files === false) {
            return [];
        }

        sort($files, SORT_STRING);

        $migrations = [];
        foreach ($files as $file) {
            $migrations[basename($file, '.sql')] = $file;
        }

        return $migrations;
    }
}
