<?php

declare(strict_types=1);

namespace App\Demo;

use App\AiUsage\AiProviderRepository;
use App\Files\FileStore;
use App\Identity\User;
use App\Identity\UserRepository;
use App\Rounds\DecisionService;
use App\Rounds\RoundService;
use App\Shared\Clock;
use App\Shared\Config;
use App\Shared\Db\Database;
use App\Shared\MutableClock;
use App\Tasks\AssignmentService;
use App\Tasks\TaskService;
use DateInterval;
use DateTimeZone;
use LogicException;
use Psr\Container\ContainerInterface;
use Psr\Log\LoggerInterface;
use RuntimeException;
use Throwable;

use function App\createContainer;

/**
 * Fills the application with the demo and empties it again.
 *
 * The scenario (DemoScenario) is played through the same services the HTTP API uses, in a container
 * of its own whose Clock is a MutableClock that starts 14 days before the real now and is set to the
 * time of each step, oldest first. So the history, the rounds, the assignments, the credits and every
 * metric derived from them agree with each other, and nothing is dated after now. Only the deadlines
 * are tied to the real now: they do not move afterwards.
 *
 * Accounts are created only when missing, without a password, and are never changed or deleted: a
 * reset never touches who can sign in. Nothing here is reachable over HTTP; the commands are guarded
 * by DemoGuard.
 */
final class DemoSeeder
{
    public function __construct(
        private readonly Config $config,
        private readonly Database $database,
        private readonly Clock $clock,
        private readonly FileStore $files,
        private readonly LoggerInterface $logger,
    ) {}

    public function hasTasks(): bool
    {
        $statement = $this->database->pdo()->query('SELECT EXISTS (SELECT 1 FROM tasks)');

        return $statement !== false && (int) $statement->fetchColumn() === 1;
    }

    /**
     * Creates the accounts that are missing, makes sure the AI providers exist and plays the scenario,
     * all in one database transaction. If it fails part way the database is rolled back and the files
     * already written are removed, so that nothing half built stays.
     *
     * @throws AlreadySeededException when any task exists
     */
    public function seed(): SeedSummary
    {
        if ($this->hasTasks()) {
            throw new AlreadySeededException();
        }

        try {
            // One transaction for all of it: the demo appears at once or not at all, and one commit
            // instead of hundreds. READ COMMITTED because RoundService asks for it and a nested
            // transaction cannot raise the isolation level of the outer one.
            return $this->database->transaction($this->play(...), readCommitted: true);
        } catch (Throwable $failure) {
            try {
                $this->wipe();
            } catch (Throwable $cleanupFailure) {
                $this->logger->error('Demo seed failed and its leftovers could not be removed.', ['exception' => $cleanupFailure]);
            }

            throw $failure;
        }
    }

    /**
     * Deletes all task data and files, then seeds.
     */
    public function reset(): SeedSummary
    {
        $this->wipe();

        return $this->seed();
    }

    /**
     * Deletes every task (and with it its geo, references, history, assignments, rounds, deliverables
     * and AI usage) and every file, rows and bytes. The files go first: they are what the RESTRICT keys
     * of the accounts hang on, and what the tasks' attachments point to. Users, sessions, login attempts
     * and security events are not touched.
     */
    public function wipe(): void
    {
        $this->files->deleteAll();
        $this->database->pdo()->exec('DELETE FROM tasks');
    }

    private function play(): SeedSummary
    {
        $now = $this->clock->now()->setTimezone(new DateTimeZone('UTC'));
        $now = $now->setTime((int) $now->format('H'), (int) $now->format('i'), (int) $now->format('s'));
        $clock = new MutableClock($now->sub(new DateInterval(DemoScenario::WINDOW)));

        $container = createContainer([
            Config::class => $this->config,
            Database::class => $this->database,
            Clock::class => $clock,
            LoggerInterface::class => $this->logger,
        ]);

        $providers = self::service($container, AiProviderRepository::class);
        $this->database->transaction(static function () use ($providers): void {
            foreach (DemoScenario::PROVIDERS as $name) {
                $providers->resolve($name);
            }
        });

        [$accounts, $created] = $this->ensureAccounts($container);
        $tasks = DemoScenario::tasks();

        // Every step of every task on one timeline, oldest first (ties keep the scenario's order).
        $timeline = [];
        foreach ($tasks as $index => $task) {
            foreach ($task->timeline() as $step) {
                $timeline[] = [$step, $index];
            }
        }
        usort($timeline, static fn(array $a, array $b): int => $b[0]->secondsAgo <=> $a[0]->secondsAgo);

        $run = new ScenarioRun(
            $now,
            $accounts,
            self::service($container, TaskService::class),
            self::service($container, AssignmentService::class),
            self::service($container, RoundService::class),
            self::service($container, DecisionService::class),
            self::service($container, PlaceholderFiles::class),
        );

        foreach ($timeline as [$step, $index]) {
            $clock->set($now->modify(sprintf('-%d seconds', $step->secondsAgo)));
            $run->perform($tasks[$index], $step);
        }

        return new SeedSummary(count($tasks), $created, $this->countFiles());
    }

    /**
     * The six accounts, created (without a password) when missing.
     *
     * @return array{array<string, User>, int} the accounts by username, and how many had to be created
     */
    private function ensureAccounts(ContainerInterface $container): array
    {
        $users = self::service($container, UserRepository::class);
        $accounts = [];
        $created = 0;

        foreach (DemoScenario::ACCOUNTS as $username => [$displayName, $role]) {
            $user = $users->findByUsername($username)?->withoutPasswordHash();
            if ($user === null) {
                $user = $users->create($username, $displayName, $role, 'en');
                ++$created;
            } elseif ($user->role !== $role) {
                throw new RuntimeException(sprintf('The account "%s" exists with the role %s, but the demo needs a %s.', $username, $user->role->value, $role->value));
            }

            $accounts[$username] = $user;
        }

        return [$accounts, $created];
    }

    private function countFiles(): int
    {
        $statement = $this->database->pdo()->query('SELECT COUNT(*) FROM files');

        return $statement === false ? 0 : (int) $statement->fetchColumn();
    }

    /**
     * @template T of object
     * @param class-string<T> $id
     * @return T
     */
    private static function service(ContainerInterface $container, string $id): object
    {
        $service = $container->get($id);

        return $service instanceof $id ? $service : throw new LogicException(sprintf('The container returned no %s.', $id));
    }
}
