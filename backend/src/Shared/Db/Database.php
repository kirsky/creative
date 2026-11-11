<?php

declare(strict_types=1);

namespace App\Shared\Db;

use App\Shared\Config;
use LogicException;
use PDO;
use Throwable;

/**
 * One lazily opened PDO connection per instance: utf8mb4, UTC, exceptions on, native prepares.
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $depth = 0;

    /** Whether the outermost transaction running now is READ COMMITTED. */
    private bool $readCommitted = false;

    public function __construct(private readonly Config $config) {}

    public function pdo(): PDO
    {
        return $this->pdo ??= $this->connect();
    }

    /**
     * Runs $fn in a transaction: commit on return, roll back and rethrow on any exception.
     * Calls nested inside a transaction use a savepoint, so an inner failure that the outer
     * code catches undoes only the inner work.
     *
     * Transactions are REPEATABLE READ with MariaDB's snapshot isolation (the default of 12.x): a
     * row that another transaction committed after this one's first read is invisible to it, and
     * locking it, or pointing a foreign key at it, fails with error 1020 "Record has changed since
     * last read". That is wrong for a transaction that must work with rows other requests create at
     * the same moment (a new AI provider that two designers report together); such a transaction
     * asks for $readCommitted, where every statement sees what is committed by then. Only the
     * outermost transaction decides: a nested call cannot ask for more than the outer one gave (that is a LogicException).
     *
     * @template T
     * @param callable(): T $fn
     * @param bool $readCommitted run the transaction at READ COMMITTED
     * @return T
     */
    public function transaction(callable $fn, bool $readCommitted = false): mixed
    {
        $pdo = $this->pdo();
        $nested = $this->depth > 0;
        $savepoint = 'sp_' . $this->depth;

        if ($nested) {
            if ($readCommitted && !$this->readCommitted) {
                throw new LogicException('A nested transaction cannot raise the isolation level of the outer one to READ COMMITTED.');
            }
            $pdo->exec('SAVEPOINT ' . $savepoint);
        } else {
            if ($readCommitted) {
                // For the next transaction only.
                $pdo->exec('SET TRANSACTION ISOLATION LEVEL READ COMMITTED');
            }
            $this->readCommitted = $readCommitted;
            $pdo->beginTransaction();
        }

        ++$this->depth;

        try {
            $result = $fn();

            if ($nested) {
                $pdo->exec('RELEASE SAVEPOINT ' . $savepoint);
            } else {
                $pdo->commit();
            }
        } catch (Throwable $e) {
            if ($nested) {
                $pdo->exec('ROLLBACK TO SAVEPOINT ' . $savepoint);
            } elseif ($pdo->inTransaction()) {
                $pdo->rollBack();
            }

            throw $e;
        } finally {
            --$this->depth;
        }

        return $result;
    }

    private function connect(): PDO
    {
        $dsn = sprintf(
            'mysql:host=%s;port=%s;dbname=%s;charset=utf8mb4',
            $this->config->get('DB_HOST'),
            $this->config->get('DB_PORT', '3306'),
            $this->config->get('DB_NAME'),
        );

        $pdo = new PDO($dsn, $this->config->get('DB_USER'), $this->config->get('DB_PASSWORD'), [
            PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
            PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            PDO::ATTR_EMULATE_PREPARES => false,
        ]);

        // Timestamps are stored in UTC whatever the server default is.
        $pdo->exec("SET NAMES utf8mb4 COLLATE utf8mb4_unicode_ci, time_zone = '+00:00'");

        return $pdo;
    }
}
