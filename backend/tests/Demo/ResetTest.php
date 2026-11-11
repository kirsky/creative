<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use App\Demo\Cli\ResetCommand;
use App\Demo\DemoScenario;
use App\Identity\PasswordHasher;
use App\Identity\Role;
use App\Identity\SecurityEventLog;
use App\Identity\SessionService;
use App\Identity\UserRepository;
use App\Shared\Http\ApiError;
use App\Tests\Files\Samples;

/**
 * demo:reset replaces the task data and the uploaded files, and nothing about who can sign in.
 */
final class ResetTest extends DemoTestCase
{
    private const array TASK_TABLES = [
        'tasks', 'task_geo', 'task_references', 'task_events', 'task_field_changes', 'assignments',
        'rounds', 'deliverables', 'ai_usage', 'files', 'file_attachments',
    ];

    public function test_reset_replaces_tasks_and_removes_uploaded_files_from_disk(): void
    {
        $this->seeder()->seed();
        $oldTaskIds = $this->ids('tasks');
        $oldFiles = $this->filesOnDisk();
        $oldFileRows = $this->ids('files');
        self::assertNotSame([], $oldFiles);
        self::assertCount(count($oldFiles), $oldFileRows);
        // A stray that no row knows about goes too; the root itself stays.
        file_put_contents($this->uploadDir . '/stray.bin', 'x');

        [$exit, $stdout, $stderr] = $this->execute($this->command(ResetCommand::class));

        self::assertSame(0, $exit, $stderr);
        self::assertStringContainsString('16 tasks', $stdout);
        self::assertSame(16, $this->countRows('tasks'));
        self::assertSame([], array_intersect($oldTaskIds, $this->ids('tasks')), 'the old tasks are gone, not kept');
        self::assertSame([], array_intersect($oldFileRows, $this->ids('files')));

        $newFiles = $this->filesOnDisk();
        self::assertCount($this->countRows('files'), $newFiles);
        self::assertSame([], array_intersect($oldFiles, $newFiles), 'no old upload survived');
        self::assertFileDoesNotExist($this->uploadDir . '/stray.bin');
        self::assertDirectoryExists($this->uploadDir, 'UPLOAD_DIR is a mount and stays');
        self::assertSame(
            ['new' => 3, 'in_progress' => 3, 'under_review' => 2, 'revisions_required' => 2, 'completed' => 4, 'cancelled' => 2],
            $this->statusCounts(),
        );
    }

    public function test_reset_removes_everything_that_hangs_on_a_task(): void
    {
        $this->seeder()->seed();
        $manager = $this->service(UserRepository::class)->findByUsername('manager');
        self::assertNotNull($manager);
        // An upload nobody attached yet, as left by a designer who closed the tab.
        $designer = $this->service(UserRepository::class)->findByUsername('anna');
        self::assertNotNull($designer);
        self::assertLessThan(300, $this->upload(Samples::png(), 'unattached.png', as: $designer)->getStatusCode());
        foreach (self::TASK_TABLES as $table) {
            self::assertGreaterThan(0, $this->countRows($table), $table . ' is part of what is seeded');
        }

        $this->seeder()->wipe();

        foreach (self::TASK_TABLES as $table) {
            self::assertSame(0, $this->countRows($table), $table);
        }
        self::assertSame([], $this->filesOnDisk());
        self::assertSame(6, $this->countRows('users'));
    }

    public function test_reset_keeps_password_hashes_and_sessions_untouched(): void
    {
        $this->seeder()->seed();
        $users = $this->service(UserRepository::class);
        $anna = $users->findByUsername('anna');
        $dmitry = $users->findByUsername('dmitry');
        self::assertNotNull($anna);
        self::assertNotNull($dmitry);
        $users->setPasswordHash($anna->id, $this->service(PasswordHasher::class)->hash('test-only password'));
        $token = $this->service(SessionService::class)->start($dmitry, '203.0.113.7');
        $this->service(SecurityEventLog::class)->record(SecurityEventLog::CREDENTIALS_ROTATED, null, ['accounts' => 6]);
        $this->database->pdo()->exec("INSERT INTO login_attempts (username, ip, attempted_at) VALUES ('anna', '203.0.113.7', '2026-01-15 08:00:00')");
        $before = $this->identityState();
        self::assertNotNull($before['users'][2]['password_hash'] ?? null);
        self::assertCount(1, $before['sessions']);
        self::assertCount(1, $before['login_attempts']);
        self::assertCount(1, $before['security_events']);

        [$exit, , $stderr] = $this->execute($this->command(ResetCommand::class));

        self::assertSame(0, $exit, $stderr);
        // Accounts keep their ids, hashes and the rest; sessions, attempts and security events are as they were.
        self::assertSame($before, $this->identityState());
        self::assertNotNull($this->service(SessionService::class)->resolve($token), 'the session still works');
        self::assertSame($anna->id, $users->findByUsername('anna')?->id);
        self::assertSame(16, $this->countRows('tasks'));
    }

    public function test_reset_removes_providers_created_by_reviewers_and_keeps_the_defaults_once(): void
    {
        $this->seeder()->seed();
        $pdo = $this->database->pdo();
        $pdo->exec("INSERT INTO ai_providers (name) VALUES ('Reviewer Junk 🎰'), ('asdf')");
        // A reviewer's line on a seeded round uses one of them, so that provider is in use until the tasks go.
        $round = self::integer($this->fetchValue('SELECT MIN(id) FROM rounds'));
        $junk = self::integer($this->fetchValue("SELECT id FROM ai_providers WHERE name = 'asdf'"));
        $pdo->prepare('INSERT INTO ai_usage (round_id, provider_id, credits) VALUES (?, ?, 1.00)')->execute([$round, $junk]);
        $defaultIds = $this->fetchRows('SELECT id, name FROM ai_providers WHERE name NOT IN (\'Reviewer Junk 🎰\', \'asdf\') ORDER BY id');
        $state = $this->identityState();

        [$exit, , $stderr] = $this->execute($this->command(ResetCommand::class));

        self::assertSame(0, $exit, $stderr);
        $names = array_map(static fn(array $row): string => self::text($row['name']), $this->fetchRows('SELECT name FROM ai_providers ORDER BY name'));
        $expected = DemoScenario::PROVIDERS;
        sort($expected);
        self::assertSame($expected, $names, 'exactly the defaults, each once');
        self::assertSame($defaultIds, $this->fetchRows('SELECT id, name FROM ai_providers ORDER BY id'), 'the defaults keep their rows');
        self::assertSame(0, self::integer($this->fetchValue('SELECT COUNT(*) FROM ai_usage WHERE provider_id = ?', [$junk])));
        self::assertSame($state, $this->identityState(), 'accounts, hashes and sessions are untouched');
    }

    public function test_reset_on_an_empty_database_attributes_the_tasks_to_the_existing_accounts(): void
    {
        $existing = $this->createUser('elena', Role::Buyer, 'test-only password');

        [$exit, , $stderr] = $this->execute($this->command(ResetCommand::class));

        self::assertSame(0, $exit, $stderr);
        self::assertSame(16, $this->countRows('tasks'));
        self::assertSame(6, $this->countRows('users'));
        self::assertGreaterThan(0, self::integer($this->fetchValue('SELECT COUNT(*) FROM tasks WHERE created_by = ?', [$existing->id])));
    }

    public function test_running_twice_gives_same_counts(): void
    {
        $this->execute($this->command(ResetCommand::class));
        $first = $this->counts();

        $this->execute($this->command(ResetCommand::class));
        $second = $this->counts();

        self::assertSame($first, $second);
        self::assertSame(16, $second['tasks']);
        self::assertSame(6, $second['users']);
        self::assertSame(count($this->filesOnDisk()), $second['files']);
    }

    public function test_refused_when_app_env_not_demo(): void
    {
        $this->seeder()->seed();
        $tasks = $this->ids('tasks');
        $files = $this->filesOnDisk();
        $state = $this->identityState();

        foreach ([['production', 'cli'], ['test', 'cli'], ['', 'cli'], ['demo', 'fpm-fcgi']] as [$appEnv, $sapi]) {
            [$exit, $stdout, $stderr] = $this->execute($this->command(ResetCommand::class, $appEnv, $sapi));

            self::assertNotSame(0, $exit, $appEnv . '/' . $sapi);
            self::assertSame('', $stdout);
            self::assertMatchesRegularExpression('/\Aerror: [^\n]*APP_ENV=demo[^\n]*\n\z/', $stderr);
            // Nothing was deleted, nothing was created.
            self::assertSame($tasks, $this->ids('tasks'));
            self::assertSame($files, $this->filesOnDisk());
            self::assertSame($state, $this->identityState());
        }
    }

    public function test_a_failed_seed_leaves_no_half_seeded_data_and_a_reset_can_follow(): void
    {
        // Every upload is refused as too large, so the seed fails part way, after it created tasks.
        $this->bootApp($this->config->with(['MAX_UPLOAD_BYTES' => '10']));

        try {
            $this->seeder()->seed();
            self::fail('A seed whose uploads are refused must fail.');
        } catch (ApiError $error) {
            self::assertSame('file_too_large', $error->code);
        }

        self::assertSame(0, $this->countRows('tasks'), 'the tasks made before the failure are removed again');
        self::assertSame(0, $this->countRows('files'));
        self::assertSame([], $this->filesOnDisk());

        $this->bootApp($this->config);
        $this->seeder()->reset();
        self::assertSame(16, $this->countRows('tasks'));
    }

    /**
     * @return list<int>
     */
    private function ids(string $table): array
    {
        return array_map(
            static fn(array $row): int => self::integer($row['id']),
            $this->fetchRows('SELECT id FROM `' . $table . '` ORDER BY id'),
        );
    }

    /**
     * @return array<string, int>
     */
    private function counts(): array
    {
        $counts = [];
        foreach ([...self::TASK_TABLES, 'users', 'ai_providers'] as $table) {
            $counts[$table] = $this->countRows($table);
        }

        return $counts;
    }
}
