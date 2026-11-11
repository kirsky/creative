<?php

declare(strict_types=1);

/*
 * PHPUnit bootstrap. The suite must never touch the application database, so
 * the database name is forced to the test database for the whole process
 * before any test (or any Config::fromEnvironment() call) can read it.
 */

require __DIR__ . '/../vendor/autoload.php';

$testDatabase = getenv('DB_TEST_NAME');
if ($testDatabase === false || $testDatabase === '') {
    fwrite(STDERR, "DB_TEST_NAME is not set; refusing to run the tests.\n");
    exit(1);
}

$applicationDatabase = getenv('DB_NAME');
if ($testDatabase === $applicationDatabase || !str_ends_with($testDatabase, '_test')) {
    fwrite(STDERR, "DB_TEST_NAME must end in _test and differ from DB_NAME; refusing to run the tests.\n");
    exit(1);
}

putenv('DB_NAME=' . $testDatabase);
$_ENV['DB_NAME'] = $testDatabase;
$_SERVER['DB_NAME'] = $testDatabase;
