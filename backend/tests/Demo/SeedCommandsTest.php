<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\Cli\ResetCommand;
use App\Demo\Cli\SeedCommand;
use App\Demo\Cli\SeedIfEmptyCommand;
use App\Identity\Role;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * The seed and the three commands around it, each from a database of its own (the tests of the result
 * of a seed are SeedTest's, which share one).
 */
final class SeedCommandsTest extends DemoTestCase
{
    public function test_accounts_that_exist_are_not_recreated_or_changed(): void
    {
        $anna = $this->createUser('anna', Role::Designer, 'test-only password');
        $annaRow = $this->fetchRows('SELECT * FROM users WHERE id = ?', [$anna->id]);

        $this->seeder()->seed();

        self::assertSame(6, $this->countRows('users'));
        self::assertSame($annaRow, $this->fetchRows('SELECT * FROM users WHERE id = ?', [$anna->id]));
        // The tasks went to the existing account.
        self::assertGreaterThan(0, self::integer($this->fetchValue('SELECT COUNT(*) FROM tasks WHERE assignee_id = ?', [$anna->id])));
    }

    public function test_providers_are_re_ensured_without_duplicates(): void
    {
        // The test database starts without providers. One exists under another spelling: not duplicated.
        $this->database->pdo()->exec("INSERT INTO ai_providers (name) VALUES ('runway'), ('Pika')");

        $this->seeder()->seed();

        $names = $this->column('SELECT name FROM ai_providers ORDER BY name');
        self::assertCount(7, $names);
        foreach (['Midjourney', 'Kling', 'ElevenLabs', 'OpenAI', 'Adobe Firefly', 'Pika'] as $name) {
            self::assertContains($name, $names);
        }
        self::assertCount(1, array_filter($names, static fn(string $name): bool => strtolower($name) === 'runway'));

        // A reset adds none and removes the one people added (Pika); the default under another spelling stays.
        $this->execute($this->command(ResetCommand::class));
        $names = $this->column('SELECT name FROM ai_providers ORDER BY name');
        self::assertCount(6, $names);
        self::assertNotContains('Pika', $names);
        self::assertContains('runway', $names);
    }

    public function test_the_seed_command_seeds_an_empty_database(): void
    {
        [$exit, $stdout, $stderr] = $this->execute($this->command(SeedCommand::class));

        self::assertSame(0, $exit, $stderr);
        self::assertSame(16, $this->countRows('tasks'));
        self::assertStringContainsString('16 tasks', $stdout);
        // No secret to print: nothing but counts.
        self::assertStringNotContainsString('password', strtolower($stdout . $stderr));
    }

    public function test_seed_if_empty_seeds_when_there_are_no_tasks(): void
    {
        // A single task is enough to count as seeded, whoever made it.
        $buyer = $this->createUser('someone', Role::Buyer);
        $this->insertTask($buyer);
        [$exit] = $this->execute($this->command(SeedIfEmptyCommand::class));
        self::assertSame(0, $exit);
        self::assertSame(1, $this->countRows('tasks'));

        $this->truncateAllTables();
        [$exit, , $stderr] = $this->execute($this->command(SeedIfEmptyCommand::class));

        self::assertSame(0, $exit, $stderr);
        self::assertSame(16, $this->countRows('tasks'));
        self::assertSame(6, $this->countRows('users'));
    }

    /**
     * @param class-string<SeedCommand|ResetCommand|SeedIfEmptyCommand> $class
     */
    #[DataProvider('commands')]
    public function test_refused_when_app_env_is_not_demo_or_sapi_is_not_cli(string $class): void
    {
        foreach ([['production', 'cli'], ['test', 'cli'], ['', 'cli'], ['Demo', 'cli'], ['demo', 'fpm-fcgi'], ['demo', 'cli-server']] as [$appEnv, $sapi]) {
            [$exit, $stdout, $stderr] = $this->execute($this->command($class, $appEnv, $sapi));

            self::assertNotSame(0, $exit, $appEnv . '/' . $sapi);
            self::assertSame('', $stdout);
            self::assertMatchesRegularExpression('/\Aerror: [^\n]*APP_ENV=demo[^\n]*\n\z/', $stderr);
            self::assertSame(0, $this->countRows('tasks'), 'nothing may be created');
            self::assertSame(0, $this->countRows('users'));
        }
    }

    public function test_the_container_builds_the_commands_from_config_and_the_php_sapi(): void
    {
        $this->bootApp($this->config->with(['APP_ENV' => 'production']));
        [$exit] = $this->execute($this->service(SeedCommand::class));
        self::assertNotSame(0, $exit);
        self::assertSame(0, $this->countRows('tasks'));

        // The tests run on the command line, so only APP_ENV decides.
        self::assertSame('cli', PHP_SAPI);
        $this->bootApp($this->config->with(['APP_ENV' => 'demo']));
        [$exit, , $stderr] = $this->execute($this->service(SeedCommand::class));
        self::assertSame(0, $exit, $stderr);
        self::assertSame(16, $this->countRows('tasks'));
    }

    public function test_the_console_binary_registers_all_three_and_they_refuse_outside_demo(): void
    {
        foreach (['demo:seed', 'demo:reset', 'demo:seed-if-empty'] as $name) {
            $process = proc_open(
                [PHP_BINARY, __DIR__ . '/../../bin/console', $name],
                [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                $pipes,
                null,
                // Nothing but a non-demo environment: the command must not get as far as the database.
                ['APP_ENV' => 'production'],
            );
            self::assertIsResource($process);
            $stdout = stream_get_contents($pipes[1]);
            $stderr = stream_get_contents($pipes[2]);
            fclose($pipes[1]);
            fclose($pipes[2]);
            $exit = proc_close($process);

            self::assertNotSame(0, $exit, $name);
            self::assertSame('', $stdout, $name);
            // "Command not defined" would also exit non-zero: the message tells the two apart.
            self::assertStringContainsString('APP_ENV=demo', (string) $stderr, $name);
        }
    }

    public function test_no_http_route_exposes_seeding(): void
    {
        foreach ($this->app->getRouteCollector()->getRoutes() as $route) {
            $pattern = strtolower($route->getPattern());
            self::assertStringNotContainsString('seed', $pattern);
            self::assertStringNotContainsString('reset', $pattern);
            self::assertStringNotContainsString('demo', $pattern);
        }
    }

    /**
     * @return array<string, array{class-string}>
     */
    public static function commands(): array
    {
        return ['seed' => [SeedCommand::class], 'reset' => [ResetCommand::class], 'seed-if-empty' => [SeedIfEmptyCommand::class]];
    }

    public function test_seeding_on_another_now_moves_the_whole_scenario_with_it(): void
    {
        $now = new DateTimeImmutable('2027-03-01 12:00:00', new DateTimeZone('UTC'));
        $this->clock->set($now);

        $this->seeder()->seed();

        $this->assertDatesAreRelativeTo($now);
        // Nothing in the data is from the clock the tests start with.
        self::assertSame('0', self::text($this->fetchValue("SELECT COUNT(*) FROM tasks WHERE created_at < '2027-02-01' OR deadline_at < '2027-02-01'")));
        self::assertSame('0', self::text($this->fetchValue("SELECT COUNT(*) FROM users WHERE created_at < '2027-02-01'")));
    }
}
