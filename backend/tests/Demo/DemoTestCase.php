<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\Cli\ResetCommand;
use App\Demo\Cli\SeedCommand;
use App\Demo\Cli\SeedIfEmptyCommand;
use App\Demo\DemoSeeder;
use App\Tests\Support\ApiTestCase;
use FilesystemIterator;
use DateInterval;
use DateTimeImmutable;
use PDO;
use ReflectionClass;
use RuntimeException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use SplFileInfo;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Shared helpers of the demo tests: the seeder from the container (with the test's clock, database
 * and upload directory), the three commands with a chosen APP_ENV and SAPI, and plain counts.
 */
abstract class DemoTestCase extends ApiTestCase
{
    /** Whether the database holds the seed that #[SharedSeed] tests share, and where its files are kept. */
    private static bool $sharedSeedInPlace = false;

    private static ?string $sharedUploads = null;

    public static function tearDownAfterClass(): void
    {
        if (self::$sharedUploads !== null) {
            self::removeTree(self::$sharedUploads);
        }
        self::$sharedUploads = null;
        self::$sharedSeedInPlace = false;

        parent::tearDownAfterClass();
    }

    /**
     * The tests of a #[SharedSeed] class leave the database as they found it, so the next one finds the
     * seed there; any other test starts from an empty database as usual.
     */
    protected function truncateAllTables(): void
    {
        if (self::$sharedSeedInPlace && $this->readsSharedSeed()) {
            return;
        }

        self::$sharedSeedInPlace = false;
        parent::truncateAllTables();
    }

    /**
     * Makes sure the database holds the seed and the test's UPLOAD_DIR its files. The first test of the
     * class that asks seeds and keeps a copy of the files; the others get the copy.
     */
    protected function sharedSeed(): void
    {
        if (self::$sharedSeedInPlace && self::$sharedUploads !== null && $this->countRows('tasks') === 16) {
            self::copyTree(self::$sharedUploads, $this->uploadDir);

            return;
        }

        // setUp emptied the database (the flag was down), so this seeds from nothing.
        $this->seeder()->seed();

        if (self::$sharedUploads !== null) {
            self::removeTree(self::$sharedUploads);
        }
        self::$sharedUploads = $this->workDir . '-shared';
        self::copyTree($this->uploadDir, self::$sharedUploads);
        self::$sharedSeedInPlace = true;
    }

    private function readsSharedSeed(): bool
    {
        return (new ReflectionClass($this))->getAttributes(SharedSeed::class) !== [];
    }

    private static function copyTree(string $from, string $to): void
    {
        if (!is_dir($to) && !mkdir($to, 0750, true)) {
            throw new RuntimeException(sprintf('Cannot create %s.', $to));
        }

        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($from, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::SELF_FIRST);
        foreach ($iterator as $entry) {
            if (!$entry instanceof SplFileInfo) {
                continue;
            }

            $target = $to . '/' . substr($entry->getPathname(), strlen($from) + 1);
            if ($entry->isDir()) {
                if (!is_dir($target) && !mkdir($target, 0750)) {
                    throw new RuntimeException(sprintf('Cannot create %s.', $target));
                }
            } elseif (!copy($entry->getPathname(), $target)) {
                throw new RuntimeException(sprintf('Cannot copy %s.', $entry->getPathname()));
            }
        }
    }

    protected function seeder(): DemoSeeder
    {
        return $this->service(DemoSeeder::class);
    }

    /**
     * @param class-string<SeedCommand|ResetCommand|SeedIfEmptyCommand> $class
     */
    protected function command(string $class, string $appEnv = 'demo', string $sapi = 'cli'): Command
    {
        return new $class($this->seeder(), $appEnv, $sapi);
    }

    /**
     * Runs a command with stdout and stderr kept apart, as a shell would.
     *
     * @return array{int, string, string} exit code, stdout, stderr
     */
    protected function execute(Command $command): array
    {
        $tester = new CommandTester($command);
        $exit = $tester->execute([], ['capture_stderr_separately' => true]);

        self::assertContains($exit, [Command::SUCCESS, Command::FAILURE, Command::INVALID]);

        return [$exit, $tester->getDisplay(), $tester->getErrorOutput()];
    }

    /**
     * Task counts by status, every status present.
     *
     * @return array<string, int>
     */
    protected function statusCounts(): array
    {
        $counts = ['new' => 0, 'in_progress' => 0, 'under_review' => 0, 'revisions_required' => 0, 'completed' => 0, 'cancelled' => 0];
        foreach ($this->fetchRows('SELECT status, COUNT(*) AS n FROM tasks GROUP BY status') as $row) {
            $counts[self::text($row['status'])] = self::integer($row['n']);
        }

        return $counts;
    }

    /**
     * Ids and (hash or null) of every account, plus every row of the tables a reset must not touch.
     *
     * @return array<string, list<array<string, mixed>>>
     */
    protected function identityState(): array
    {
        $state = [];
        foreach (['users' => 'id', 'sessions' => 'token_hash', 'login_attempts' => 'id', 'security_events' => 'id'] as $table => $order) {
            $statement = $this->database->pdo()->query('SELECT * FROM `' . $table . '` ORDER BY `' . $order . '`');
            self::assertNotFalse($statement);
            $rows = [];
            foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
                self::assertIsArray($row);
                $named = [];
                foreach ($row as $column => $value) {
                    $named[(string) $column] = $value;
                }
                $rows[] = $named;
            }
            $state[$table] = $rows;
        }

        return $state;
    }

    protected static function text(mixed $value): string
    {
        return is_string($value) || is_int($value) ? (string) $value : self::fail('Expected a string column.');
    }

    protected static function integer(mixed $value): int
    {
        return is_numeric($value) ? (int) $value : self::fail('Expected a number column.');
    }

    /**
     * Every file under the test's UPLOAD_DIR, as paths relative to it, sorted.
     *
     * @return list<string>
     */
    protected function filesOnDisk(): array
    {
        $found = [];
        $iterator = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($this->uploadDir, FilesystemIterator::SKIP_DOTS));
        foreach ($iterator as $file) {
            if ($file instanceof SplFileInfo && $file->isFile()) {
                $found[] = substr($file->getPathname(), strlen($this->uploadDir) + 1);
            }
        }
        sort($found);

        return $found;
    }

    /**
     * For the tests of a seed on any "now": history inside the 14 days before it, nothing
     * dated after it, deadlines at the offsets of the scenario.
     */
    protected function assertDatesAreRelativeTo(DateTimeImmutable $now): void
    {
        $format = 'Y-m-d H:i:s';
        self::assertSame('0', self::text($this->fetchValue('SELECT COUNT(*) FROM task_events WHERE at > ?', [$now->format('Y-m-d H:i:s.u')])));
        self::assertSame('0', self::text($this->fetchValue('SELECT COUNT(*) FROM task_events WHERE at < ?', [$now->sub(new DateInterval('P14D'))->format('Y-m-d H:i:s.u')])));

        // The deadlines that make the board: overdue by 2 d and 1 d, due today, due soon.
        $deadline = fn(string $offer): string => self::text($this->fetchValue('SELECT deadline_at FROM tasks WHERE offer = ?', [$offer]));
        self::assertSame($now->sub(new DateInterval('P2D'))->format($format), $deadline('Мега Спин'));
        self::assertSame($now->sub(new DateInterval('P1D'))->format($format), $deadline('Лото Клуб'));
        self::assertSame($now->add(new DateInterval('PT3H'))->format($format), $deadline('Vulkan Bet'));
        self::assertSame($now->add(new DateInterval('P1DT4H'))->format($format), $deadline('Casino Royal'));

        // And exactly two open tasks are past their deadline.
        self::assertSame('2', self::text($this->fetchValue("SELECT COUNT(*) FROM tasks WHERE deadline_at < ? AND status NOT IN ('completed', 'cancelled')", [$now->format($format)])));
    }

    /**
     * The first column of every row a query returns, as strings.
     *
     * @param list<int|string> $parameters
     * @return list<string>
     */
    protected function column(string $sql, array $parameters = []): array
    {
        $values = [];
        foreach ($this->fetchRows($sql, $parameters) as $row) {
            $values[] = self::text(reset($row));
        }

        return $values;
    }
}
