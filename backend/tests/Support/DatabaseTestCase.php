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
    private static bool $migrated = false;

    protected Config $config;

    protected Database $database;

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

        $this->config = self::testConfig();
        $this->database = new Database($this->config);
        $this->truncateAllTables();
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
            // Never the real uploads volume.
            'UPLOAD_DIR' => sys_get_temp_dir() . '/creative-test-uploads',
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
