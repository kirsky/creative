<?php

declare(strict_types=1);

use App\Shared\Config;

/*
 * Phinx configuration. Credentials come from the same environment variables the compose file
 * passes to the php container. Environments:
 *   creative  the application database (the default)
 *   test      the PHPUnit database; only the test suite uses it
 */

$config = Config::fromEnvironment();

$connection = static fn(string $database): array => [
    'adapter' => 'mysql',
    'host' => $config->get('DB_HOST'),
    'port' => (int) $config->get('DB_PORT', '3306'),
    'name' => $database,
    'user' => $config->get('DB_USER'),
    'pass' => $config->get('DB_PASSWORD'),
    'charset' => 'utf8mb4',
    'collation' => 'utf8mb4_unicode_ci',
];

return [
    'paths' => [
        'migrations' => '%%PHINX_CONFIG_DIR%%/db/migrations',
        'seeds' => '%%PHINX_CONFIG_DIR%%/db/seeds',
    ],
    'environments' => [
        'default_migration_table' => 'phinxlog',
        'default_environment' => 'creative',
        'creative' => $connection($config->get('DB_NAME')),
        'test' => $connection($config->get('DB_TEST_NAME')),
    ],
    'version_order' => 'creation',
];
