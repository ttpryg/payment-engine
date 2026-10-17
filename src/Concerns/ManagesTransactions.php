<?php

declare(strict_types=1);

namespace Ttpryg\PaymentEngine\Concerns;

use PDO;
use Throwable;

trait ManagesTransactions
{
    /**
     * Execute the given callback inside a database transaction when a PDO
     * connection is available. When a transaction is already active the
     * callback simply joins it, since PDO has no support for nested
     * transactions. This makes the helper safe to compose across services.
     *
     * @template TReturn
     *
     * @param  callable(): TReturn  $callback
     * @return TReturn
     */
    protected function runInTransaction(?PDO $pdo, callable $callback): mixed
    {
        if (! $pdo instanceof PDO || $pdo->inTransaction()) {
            return $callback();
        }

        $pdo->beginTransaction();

        try {
            $result = $callback();
            $pdo->commit();

            return $result;
        } catch (Throwable $exception) {
            if ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $exception;
        }
    }
}
