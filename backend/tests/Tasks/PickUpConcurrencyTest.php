<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tests\Support\ApiTestCase;
use RuntimeException;

/**
 * Two designers pick up the same New task at the same instant, in two real operating system
 * processes with a connection each. Exactly one wins; the other is told it was taken. No sleeps:
 * the processes boot, sign in, and wait on a file lock that this test releases once both are ready.
 */
final class PickUpConcurrencyTest extends ApiTestCase
{
    private const ROUNDS = 20;

    public function test_two_simultaneous_pickups_exactly_one_wins(): void
    {
        $anna = $this->createUser('anna', Role::Buyer);
        $designers = [$this->createUser('dave', Role::Designer), $this->createUser('eve', Role::Designer)];

        $winners = [];
        for ($round = 0; $round < self::ROUNDS; ++$round) {
            $taskId = $this->insertTask($anna, 'Лото Клуб ' . $round);
            // Alternate the order they start in, so neither designer is always first in line.
            $contenders = $round % 2 === 0 ? $designers : array_reverse($designers);

            $results = $this->raceForTask($taskId, $contenders);

            $statuses = array_column($results, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, sprintf('Round %d: %s', $round, json_encode($results, JSON_THROW_ON_ERROR)));

            $winner = null;
            foreach ($results as $designerId => $result) {
                $body = json_decode($result['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertIsArray($body);
                if ($result['status'] === 200) {
                    $winner = $designerId;
                    self::assertSame('in_progress', $body['status']);
                    self::assertIsArray($body['assignments']);
                    self::assertCount(1, $body['assignments']);
                } else {
                    self::assertSame(['error' => ['code' => 'already_taken', 'fields' => []]], $body);
                }
            }
            self::assertNotNull($winner);
            $winners[$winner] = true;

            self::assertSame(
                [['designer_id' => $winner]],
                $this->fetchRows('SELECT designer_id FROM assignments WHERE task_id = ? AND ended_at IS NULL', [$taskId]),
                sprintf('Round %d: not exactly one open assignment.', $round),
            );
            self::assertSame(1, $this->fetchValue('SELECT COUNT(*) FROM assignments WHERE task_id = ?', [$taskId]));
            self::assertSame(1, $this->fetchValue("SELECT COUNT(*) FROM task_events WHERE task_id = ? AND type = 'task_picked_up'", [$taskId]));
            self::assertSame(
                [['status' => 'in_progress', 'assignee_id' => $winner]],
                $this->fetchRows('SELECT status, assignee_id FROM tasks WHERE id = ?', [$taskId]),
            );
        }

        self::assertSame(self::ROUNDS, $this->countRows('assignments'));
        self::assertNotEmpty($winners);
    }

    /**
     * Starts one process per designer, waits until all are ready, lets them go together and collects
     * what each answered.
     *
     * @param list<User> $contenders
     * @return array<int, array{status: int, body: string}> by designer id
     */
    private function raceForTask(int $taskId, array $contenders): array
    {
        $gateFile = $this->workDir . '/gate';
        $gate = fopen($gateFile, 'w');
        if ($gate === false || !flock($gate, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the gate.');
        }

        $script = (string) realpath(__DIR__ . '/../Support/bin/pickup_process.php');
        $processes = [];

        try {
            foreach ($contenders as $designer) {
                $pipes = [];
                $process = proc_open(
                    [PHP_BINARY, $script, (string) $designer->id, (string) $taskId, $gateFile],
                    [1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
                    $pipes,
                );
                if (!is_resource($process)) {
                    throw new RuntimeException('Cannot start the contender process.');
                }
                $processes[$designer->id] = [$process, $pipes];
            }

            foreach ($processes as [, $pipes]) {
                $line = fgets($pipes[1]);
                if ($line === false || trim($line) !== 'ready') {
                    throw new RuntimeException('A contender did not get ready: ' . stream_get_contents($pipes[2]));
                }
            }

            // Both are blocked on the gate: let them go together.
            flock($gate, LOCK_UN);

            $results = [];
            foreach ($processes as $designerId => [, $pipes]) {
                $output = stream_get_contents($pipes[1]);
                $errors = stream_get_contents($pipes[2]);
                $decoded = json_decode(trim((string) $output), true);
                if (!is_array($decoded) || !is_int($decoded['status'] ?? null) || !is_string($decoded['body'] ?? null)) {
                    throw new RuntimeException(sprintf('A contender gave no answer. Output: %s Errors: %s', $output, $errors));
                }
                $results[$designerId] = ['status' => $decoded['status'], 'body' => $decoded['body']];
            }

            return $results;
        } finally {
            flock($gate, LOCK_UN);
            fclose($gate);
            foreach ($processes as [$process, $pipes]) {
                fclose($pipes[1]);
                fclose($pipes[2]);
                proc_close($process);
            }
        }
    }
}
