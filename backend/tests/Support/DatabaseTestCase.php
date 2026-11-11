<?php

declare(strict_types=1);

namespace App\Tests\Support;

use App\Shared\Config;
use App\Shared\Db\Database;
use PDO;
use Phinx\Config\Config as PhinxConfig;
use Phinx\Migration\Manager;
use PHPUnit\Framework\TestCase;
use RuntimeException;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

/**
 * Base class for tests that use the real MariaDB test database (creative_test).
 *
 * The Phinx migrations run once per test run; every table except the Phinx log is truncated
 * before each test, so tests may commit freely (and may use several connections). Nothing here
 * can reach the application database: tests/bootstrap.php points DB_NAME at the test database,
 * and truncation refuses any database whose name does not end in "_test".
 */
abstract class DatabaseTestCase extends TestCase
{
    private const WORK_DIR_PREFIX = 'creative-test-';

    private static bool $migrated = false;

    protected Config $config;

    protected Database $database;

    /** A directory of this test alone (removed in tearDown), for anything it writes to disk. */
    protected string $workDir;

    /** The test's UPLOAD_DIR, inside $workDir: never the real uploads volume. */
    protected string $uploadDir;

    public static function setUpBeforeClass(): void
    {
        parent::setUpBeforeClass();

        if (!self::$migrated) {
            self::migrate();
            self::$migrated = true;
        }
    }

    protected function setUp(): void
    {
        parent::setUp();

        $this->workDir = sys_get_temp_dir() . '/' . self::WORK_DIR_PREFIX . bin2hex(random_bytes(8));
        $this->uploadDir = $this->workDir . '/uploads';
        if (!mkdir($this->workDir, 0700) || !mkdir($this->uploadDir, 0750)) {
            throw new RuntimeException(sprintf('Cannot create the test directory %s.', $this->workDir));
        }

        $this->config = self::testConfig()->with(['UPLOAD_DIR' => $this->uploadDir]);
        $this->database = new Database($this->config);
        $this->truncateAllTables();
    }

    protected function tearDown(): void
    {
        self::removeTree($this->workDir);

        parent::tearDown();
    }

    /**
     * Deletes a directory tree made by a test. Symbolic links are removed, never followed, and
     * nothing outside the temporary directory's test directories can be touched.
     */
    protected static function removeTree(string $path): void
    {
        if (!str_starts_with($path, sys_get_temp_dir() . '/' . self::WORK_DIR_PREFIX) || str_contains($path, '..')) {
            throw new RuntimeException(sprintf('Refusing to delete %s: not a test directory.', $path));
        }

        if (is_link($path) || is_file($path)) {
            unlink($path);

            return;
        }

        if (!is_dir($path)) {
            return;
        }

        // A test may have made a directory unreadable on purpose.
        chmod($path, 0700);
        foreach (scandir($path) ?: [] as $entry) {
            if ($entry !== '.' && $entry !== '..') {
                self::removeTree($path . '/' . $entry);
            }
        }

        rmdir($path);
    }

    /**
     * Throws unless $name is a test database. The one place that decides what tests may wipe.
     */
    public static function requireTestDatabase(string $name): void
    {
        if (!str_ends_with($name, '_test')) {
            throw new RuntimeException(sprintf('Refusing to touch database "%s": test databases must be named *_test.', $name));
        }
    }

    protected static function testConfig(): Config
    {
        $config = Config::fromEnvironment();

        return $config->with([
            'APP_ENV' => 'test',
            'DB_NAME' => $config->get('DB_TEST_NAME'),
            // Never the real uploads volume. Each test gets a directory of its own (see setUp).
            'UPLOAD_DIR' => sys_get_temp_dir() . '/' . self::WORK_DIR_PREFIX . 'unused-uploads',
        ]);
    }

    protected function truncateAllTables(): void
    {
        $pdo = $this->database->pdo();

        $current = $pdo->query('SELECT DATABASE()');
        $database = $current === false ? false : $current->fetchColumn();
        self::requireTestDatabase(is_string($database) ? $database : '');

        $statement = $pdo->query(
            "SELECT TABLE_NAME FROM information_schema.TABLES
             WHERE TABLE_SCHEMA = DATABASE() AND TABLE_TYPE = 'BASE TABLE' AND TABLE_NAME <> 'phinxlog'",
        );
        $tables = $statement === false ? [] : $statement->fetchAll(PDO::FETCH_COLUMN);

        if ($tables === []) {
            return;
        }

        $pdo->exec('SET FOREIGN_KEY_CHECKS = 0');

        try {
            foreach ($tables as $table) {
                if (is_string($table)) {
                    $pdo->exec('TRUNCATE TABLE `' . str_replace('`', '``', $table) . '`');
                }
            }
        } finally {
            $pdo->exec('SET FOREIGN_KEY_CHECKS = 1');
        }
    }

    private static function migrate(): void
    {
        self::requireTestDatabase(self::testConfig()->get('DB_NAME'));

        $phinx = PhinxConfig::fromPhp(__DIR__ . '/../../phinx.php');
        $manager = new Manager($phinx, new ArrayInput([]), new BufferedOutput());
        $manager->migrate('test');
    }
}
