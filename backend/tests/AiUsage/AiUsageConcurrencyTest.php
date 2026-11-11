<?php

declare(strict_types=1);

namespace App\Tests\AiUsage;

use App\Tasks\TaskStatus;
use App\Tests\Support\RaceRunner;

/**
 * AI usage under concurrency, in real operating system processes with a connection each (see
 * RaceRunner): two requests that create the same new provider, and an edit racing the buyer's decision.
 */
final class AiUsageConcurrencyTest extends AiUsageTestCase
{
    private const int ATTEMPTS = 12;

    public function test_two_requests_creating_the_same_new_provider_make_one_row_and_no_500(): void
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $name = 'Pika ' . $attempt;
            $daves = $this->taskOfDave();
            $eves = $this->insertTask($this->anna, status: TaskStatus::InProgress, assignee: $this->eve);

            $answers = RaceRunner::run($this->workDir, [
                $this->submitRequest($daves, $this->dave->id, $this->uploadFor($this->dave), [['provider' => $name, 'credits' => '1']]),
                $this->submitRequest($eves, $this->eve->id, $this->uploadFor($this->eve), [['provider' => strtoupper($name), 'credits' => '2']]),
            ]);

            $context = sprintf('Attempt %d: %s', $attempt, json_encode($answers, JSON_THROW_ON_ERROR));
            self::assertSame([201, 201], array_column($answers, 'status'), $context);
            self::assertSame(1, $this->fetchValue('SELECT COUNT(*) FROM ai_providers WHERE name = ?', [$name]), $context);
            self::assertSame($attempt + 1, $this->countRows('ai_providers'), $context);
            self::assertSame(1, $this->fetchValue('SELECT COUNT(DISTINCT u.provider_id) FROM ai_usage u JOIN rounds r ON r.id = u.round_id WHERE r.task_id IN (?, ?)', [$daves, $eves]), $context);
            self::assertSame(2, $this->fetchValue('SELECT COUNT(*) FROM ai_usage u JOIN rounds r ON r.id = u.round_id WHERE r.task_id IN (?, ?)', [$daves, $eves]), $context);
            // Whoever lost the race still reports the spelling that was stored (the winner's).
            $stored = $this->fetchValue('SELECT name FROM ai_providers WHERE name = ?', [$name]);
            foreach ([$daves, $eves] as $task) {
                self::assertSame([['provider' => $stored, 'credits' => $task === $daves ? '1.00' : '2.00']], $this->storedLines($task, 1), $context);
                $line = $this->usageEvents($task)[0]['lines'] ?? null;
                self::assertSame([['provider' => $stored, 'credits' => $task === $daves ? '1.00' : '2.00']], $line, $context);
            }
        }
    }

    public function test_requests_creating_the_same_new_providers_in_opposite_order_do_not_deadlock(): void
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $one = 'Alpha ' . $attempt;
            $two = 'Beta ' . $attempt;
            $daves = $this->taskOfDave();
            $eves = $this->insertTask($this->anna, status: TaskStatus::InProgress, assignee: $this->eve);

            $answers = RaceRunner::run($this->workDir, [
                $this->submitRequest($daves, $this->dave->id, $this->uploadFor($this->dave), [['provider' => $one, 'credits' => '1'], ['provider' => $two, 'credits' => '1']]),
                $this->submitRequest($eves, $this->eve->id, $this->uploadFor($this->eve), [['provider' => $two, 'credits' => '1'], ['provider' => $one, 'credits' => '1']]),
            ]);

            $context = sprintf('Attempt %d: %s', $attempt, json_encode($answers, JSON_THROW_ON_ERROR));
            self::assertSame([201, 201], array_column($answers, 'status'), $context);
            self::assertSame(2 * ($attempt + 1), $this->countRows('ai_providers'), $context);
        }
    }

    public function test_an_edit_racing_the_decision_either_lands_before_it_or_is_refused(): void
    {
        $edited = 0;
        $refused = 0;

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $id = $this->taskInStatus(TaskStatus::UnderReview);
            $round = $this->firstRound($id);
            $this->usage($round, 'Kling', '10');

            $answers = RaceRunner::run($this->workDir, [
                ['userId' => $this->dave->id, 'method' => 'PUT', 'path' => '/api/tasks/' . $id . '/rounds/1/ai-usage', 'body' => ['lines' => [['provider' => 'Kling', 'credits' => '99']]]],
                ['userId' => $this->anna->id, 'method' => 'POST', 'path' => '/api/tasks/' . $id . '/approve', 'body' => ['note' => 'ok']],
            ]);

            $context = sprintf('Attempt %d: %s', $attempt, json_encode($answers, JSON_THROW_ON_ERROR));
            self::assertSame(200, $answers[1]['status'], $context);
            self::assertContains($answers[0]['status'], [200, 409], $context);
            if ($answers[0]['status'] === 200) {
                ++$edited;
                self::assertSame([['provider' => 'Kling', 'credits' => '99.00']], $this->storedLines($id, 1), $context);
                self::assertCount(1, $this->usageEvents($id), $context);
            } else {
                ++$refused;
                self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1), $context);
                self::assertSame([], $this->usageEvents($id), $context);
            }
        }

        self::assertSame(self::ATTEMPTS, $edited + $refused);
    }

    /**
     * @param list<array{provider: string, credits: string}> $lines
     * @return array{userId: int, method: string, path: string, body: array<string, mixed>}
     */
    private function submitRequest(int $taskId, int $userId, int $fileId, array $lines): array
    {
        return [
            'userId' => $userId,
            'method' => 'POST',
            'path' => '/api/tasks/' . $taskId . '/rounds',
            'body' => ['fileIds' => [$fileId], 'aiUsage' => $lines],
        ];
    }
}
