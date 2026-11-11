<?php

declare(strict_types=1);

namespace App\Tests\AiUsage;

use App\Identity\User;
use App\Tests\Rounds\RoundsTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared helpers of the AI usage tests: providers and usage lines written straight into the tables,
 * and the two requests that carry lines (submit and replace).
 */
abstract class AiUsageTestCase extends RoundsTestCase
{
    /**
     * Inserts a provider (the test base truncates every table, so tests make the providers they use).
     */
    protected function provider(string $name): int
    {
        $pdo = $this->database->pdo();
        $pdo->prepare('INSERT INTO ai_providers (name) VALUES (?)')->execute([$name]);

        return (int) $pdo->lastInsertId();
    }

    /**
     * Inserts a usage line of an existing round straight into the table, creating the provider if needed.
     */
    protected function usage(int $roundId, string $provider, string $credits): void
    {
        $id = $this->fetchValue('SELECT id FROM ai_providers WHERE name = ?', [$provider]);
        $providerId = is_numeric($id) ? (int) $id : $this->provider($provider);
        $this->database->pdo()->prepare('INSERT INTO ai_usage (round_id, provider_id, credits) VALUES (?, ?, ?)')
            ->execute([$roundId, $providerId, $credits]);
    }

    /**
     * The id of round 1 of a task (the one taskInStatus(UnderReview) makes).
     */
    protected function firstRound(int $taskId): int
    {
        $id = $this->fetchValue('SELECT id FROM rounds WHERE task_id = ? AND number = 1', [$taskId]);

        return is_numeric($id) ? (int) $id : self::fail('The task has no round 1.');
    }

    /**
     * Submits a round for a designer: uploads one deliverable and sends it with the lines.
     */
    protected function submitWithUsage(int $taskId, mixed $aiUsage, ?User $as = null): ResponseInterface
    {
        $designer = $as ?? $this->dave;

        return $this->submit($taskId, [$this->uploadFor($designer)], $designer, ['aiUsage' => $aiUsage]);
    }

    protected function replace(int $taskId, int $round, mixed $lines, ?User $as = null): ResponseInterface
    {
        return $this->request('PUT', '/api/tasks/' . $taskId . '/rounds/' . $round . '/ai-usage', ['lines' => $lines], $as ?? $this->dave);
    }

    /**
     * The lines stored for a round, in insertion order.
     *
     * @return list<array{provider: string, credits: string}>
     */
    protected function storedLines(int $taskId, int $round): array
    {
        $lines = [];
        foreach ($this->fetchRows(
            'SELECT p.name, u.credits FROM ai_usage u
             JOIN ai_providers p ON p.id = u.provider_id
             JOIN rounds r ON r.id = u.round_id
             WHERE r.task_id = ? AND r.number = ? ORDER BY u.id',
            [$taskId, $round],
        ) as $row) {
            $lines[] = ['provider' => self::text($row['name']), 'credits' => self::text($row['credits'])];
        }

        return $lines;
    }

    /**
     * The ai_usage_changed events of a task, oldest first: their payloads.
     *
     * @return list<array<string, mixed>>
     */
    protected function usageEvents(int $taskId): array
    {
        $events = [];
        foreach ($this->fetchRows("SELECT payload FROM task_events WHERE task_id = ? AND type = 'ai_usage_changed' ORDER BY id", [$taskId]) as $row) {
            $payload = json_decode(self::text($row['payload']), true, 512, JSON_THROW_ON_ERROR);
            if (!is_array($payload)) {
                self::fail('An event payload is not an object.');
            }
            $event = [];
            foreach ($payload as $key => $value) {
                $event[(string) $key] = $value;
            }
            $events[] = $event;
        }

        return $events;
    }

    /**
     * @return list<string>
     */
    protected function eventTypes(int $taskId): array
    {
        return array_map(
            static fn(array $row): string => is_string($row['type']) ? $row['type'] : '',
            $this->fetchRows('SELECT type FROM task_events WHERE task_id = ? ORDER BY id', [$taskId]),
        );
    }
}
