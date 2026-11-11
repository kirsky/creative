<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Config;
use App\Shared\Db\Database;
use App\Tests\Support\DatabaseTestCase;
use LogicException;
use PDO;
use PDOException;
use PDOStatement;
use RuntimeException;

final class DatabaseTest extends DatabaseTestCase
{
    public function test_it_is_connected_to_the_test_database_never_the_application_one(): void
    {
        $name = $this->scalar('SELECT DATABASE()');

        self::assertIsString($name);
        self::assertStringEndsWith('_test', $name);
        self::assertSame(Config::fromEnvironment()->get('DB_TEST_NAME'), $name);
    }

    public function test_connection_is_utf8mb4_utc_with_exceptions_and_native_prepares(): void
    {
        $pdo = $this->database->pdo();

        self::assertSame(PDO::ERRMODE_EXCEPTION, $pdo->getAttribute(PDO::ATTR_ERRMODE));
        self::assertFalse($pdo->getAttribute(PDO::ATTR_EMULATE_PREPARES));
        self::assertSame('utf8mb4', $this->scalar('SELECT @@character_set_client'));
        self::assertSame('utf8mb4', $this->scalar('SELECT @@character_set_connection'));
        self::assertSame('utf8mb4', $this->scalar('SELECT @@character_set_results'));
        self::assertSame('+00:00', $this->scalar('SELECT @@session.time_zone'));
    }

    public function test_it_reuses_one_connection(): void
    {
        self::assertSame($this->database->pdo(), $this->database->pdo());
    }

    public function test_emoji_cyrillic_and_markup_round_trip_byte_identical(): void
    {
        $text = '🎰 <script>alert(1)</script> Лото';
        $pdo = $this->database->pdo();
        $pdo->exec('CREATE TEMPORARY TABLE round_trip (id INT PRIMARY KEY, body VARCHAR(255) NOT NULL)');

        $pdo->prepare('INSERT INTO round_trip (id, body) VALUES (?, ?)')->execute([1, $text]);

        self::assertSame($text, $this->scalar('SELECT body FROM round_trip WHERE id = 1'));
    }

    public function test_native_prepares_keep_integers_and_decimals_typed(): void
    {
        $row = $this->query("SELECT 7 AS n, CAST('12.50' AS DECIMAL(12,2)) AS credits")->fetch();

        self::assertSame(['n' => 7, 'credits' => '12.50'], $row);
    }

    public function test_a_failing_statement_throws(): void
    {
        $this->expectException(PDOException::class);
        $this->database->pdo()->query('SELECT * FROM table_that_does_not_exist');
    }

    public function test_transaction_commits_and_returns_the_callback_result(): void
    {
        $this->database->pdo()->exec('CREATE TEMPORARY TABLE tx (id INT PRIMARY KEY)');

        $result = $this->database->transaction(function (): string {
            $this->database->pdo()->exec('INSERT INTO tx (id) VALUES (1)');

            return 'done';
        });

        self::assertSame('done', $result);
        self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM tx'));
        self::assertFalse($this->database->pdo()->inTransaction());
    }

    public function test_transaction_rolls_back_and_rethrows_when_the_callback_throws(): void
    {
        $this->database->pdo()->exec('CREATE TEMPORARY TABLE tx (id INT PRIMARY KEY)');

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('abort');

        try {
            $this->database->transaction(function (): never {
                $this->database->pdo()->exec('INSERT INTO tx (id) VALUES (1)');

                throw new RuntimeException('abort');
            });
        } finally {
            // Runs before PHPUnit sees the exception: the insert is undone and no transaction is left open.
            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM tx'));
            self::assertFalse($this->database->pdo()->inTransaction());
        }
    }

    public function test_a_failed_inner_transaction_rolls_back_only_its_own_work(): void
    {
        $this->database->pdo()->exec('CREATE TEMPORARY TABLE tx (id INT PRIMARY KEY)');

        $this->database->transaction(function (): void {
            $this->database->pdo()->exec('INSERT INTO tx (id) VALUES (1)');

            try {
                $this->database->transaction(function (): void {
                    $this->database->pdo()->exec('INSERT INTO tx (id) VALUES (2)');

                    throw new LogicException('inner');
                });
            } catch (LogicException) {
                // The outer transaction carries on.
            }

            $this->database->pdo()->exec('INSERT INTO tx (id) VALUES (3)');
        });

        $ids = $this->query('SELECT id FROM tx ORDER BY id')->fetchAll(PDO::FETCH_COLUMN);
        self::assertSame([1, 3], $ids);
    }

    public function test_an_outer_failure_rolls_back_a_completed_inner_transaction(): void
    {
        $this->database->pdo()->exec('CREATE TEMPORARY TABLE tx (id INT PRIMARY KEY)');

        try {
            $this->database->transaction(function (): void {
                $this->database->transaction(function (): void {
                    $this->database->pdo()->exec('INSERT INTO tx (id) VALUES (1)');
                });

                throw new RuntimeException('outer');
            });
        } catch (RuntimeException) {
        }

        self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM tx'));
    }

    public function test_committed_data_is_visible_to_a_second_connection(): void
    {
        // Real table, not temporary: only committed rows can be seen from another connection.
        $this->database->pdo()->exec('CREATE TABLE IF NOT EXISTS visibility_probe (id INT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $this->database->transaction(function (): void {
                $this->database->pdo()->exec('INSERT INTO visibility_probe (id) VALUES (1)');
            });

            $other = new Database($this->config);
            $statement = $other->pdo()->query('SELECT COUNT(*) FROM visibility_probe');
            self::assertNotFalse($statement);
            self::assertEquals(1, $statement->fetchColumn());
        } finally {
            $this->database->pdo()->exec('DROP TABLE visibility_probe');
        }
    }

    public function test_read_committed_transactions_see_rows_committed_by_others_after_their_first_read(): void
    {
        $this->database->pdo()->exec('CREATE TABLE IF NOT EXISTS isolation_probe (id INT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $other = new Database($this->config);
            // READ COMMITTED first: asking for it applies to that transaction only.
            foreach ([true, false] as $readCommitted) {
                $this->database->pdo()->exec('DELETE FROM isolation_probe');

                $seen = $this->database->transaction(function () use ($other): array {
                    $before = $this->scalar('SELECT COUNT(*) FROM isolation_probe');
                    $other->pdo()->exec('INSERT INTO isolation_probe (id) VALUES (1)');

                    return [$before, $this->scalar('SELECT COUNT(*) FROM isolation_probe')];
                }, $readCommitted);

                self::assertEquals($readCommitted ? [0, 1] : [0, 0], $seen);
            }
        } finally {
            $this->database->pdo()->exec('DROP TABLE isolation_probe');
        }
    }

    public function test_a_nested_transaction_cannot_ask_for_a_stronger_isolation_than_the_outer_one_has(): void
    {
        $this->database->transaction(function (): void {
            $this->database->transaction(static fn(): null => null, true);
        }, true);

        $this->expectException(LogicException::class);
        $this->database->transaction(function (): void {
            $this->database->transaction(static fn(): null => null, true);
        });
    }

    public function test_every_table_except_the_phinx_log_is_truncated_between_tests(): void
    {
        $pdo = $this->database->pdo();
        $pdo->exec('CREATE TABLE IF NOT EXISTS truncate_probe (id INT PRIMARY KEY) ENGINE=InnoDB');

        try {
            $pdo->exec('INSERT INTO truncate_probe (id) VALUES (1), (2)');
            $pdo->exec("INSERT INTO phinxlog (version, migration_name, start_time, end_time, breakpoint) VALUES (1, 'probe', NOW(), NOW(), 0)");

            $this->truncateAllTables();

            self::assertSame(0, $this->scalar('SELECT COUNT(*) FROM truncate_probe'));
            self::assertSame(1, $this->scalar('SELECT COUNT(*) FROM phinxlog WHERE version = 1'));
        } finally {
            $pdo->exec('DELETE FROM phinxlog WHERE version = 1');
            $pdo->exec('DROP TABLE truncate_probe');
        }
    }

    public function test_truncation_refuses_to_run_against_a_database_not_named_for_tests(): void
    {
        self::requireTestDatabase('creative_test');

        $this->expectException(RuntimeException::class);
        self::requireTestDatabase('creative');
    }

    private function query(string $sql): PDOStatement
    {
        $statement = $this->database->pdo()->query($sql);
        self::assertNotFalse($statement);

        return $statement;
    }

    private function scalar(string $sql): mixed
    {
        return $this->query($sql)->fetchColumn();
    }
}
