<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RaceRunner;

/**
 * Two designers pick up the same New task at the same instant, in two real operating system
 * processes with a connection each (see RaceRunner). Exactly one wins; the other is told it was taken.
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
     * Sends one pick-up per designer at the same instant, from a process each.
     *
     * @param list<User> $contenders
     * @return array<int, array{status: int, body: string}> by designer id
     */
    private function raceForTask(int $taskId, array $contenders): array
    {
        $answers = RaceRunner::run($this->workDir, array_map(
            static fn(User $designer): array => ['userId' => $designer->id, 'method' => 'POST', 'path' => '/api/tasks/' . $taskId . '/pickup'],
            $contenders,
        ));

        $results = [];
        foreach ($contenders as $index => $designer) {
            $results[$designer->id] = $answers[$index];
        }

        return $results;
    }
}
