<?php

declare(strict_types=1);

namespace App\Shared\Db;

use App\Shared\Config;
use PDO;
use Throwable;

/**
 * One lazily opened PDO connection per instance: utf8mb4, UTC, exceptions on, native prepares.
 */
final class Database
{
    private ?PDO $pdo = null;

    private int $depth = 0;

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
     * @template T
     * @param callable(): T $fn
     * @return T
     */
    public function transaction(callable $fn): mixed
    {
        $pdo = $this->pdo();
        $nested = $this->depth > 0;
        $savepoint = 'sp_' . $this->depth;

        if ($nested) {
            $pdo->exec('SAVEPOINT ' . $savepoint);
        } else {
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
