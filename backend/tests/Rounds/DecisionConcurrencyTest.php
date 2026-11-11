<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Tasks\TaskStatus;
use App\Tests\Support\RaceRunner;

/**
 * The buyer's decisions racing each other, in two real operating system processes with a connection
 * each (see RaceRunner): a double click on Approve, and Approve against Cancel. Exactly one request
 * changes the task; the other is told the task is no longer under review or open.
 */
final class DecisionConcurrencyTest extends RoundsTestCase
{
    private const int ATTEMPTS = 10;

    public function test_approve_racing_cancel_has_exactly_one_winner(): void
    {
        $winners = ['approve' => 0, 'cancel' => 0];

        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $id = $this->taskInStatus(TaskStatus::UnderReview);
            $answers = RaceRunner::run($this->workDir, [
                $this->post($id, 'approve', ['note' => 'ok']),
                $this->post($id, 'cancel', ['reason' => 'dropped']),
            ]);

            $context = sprintf('Attempt %d: %s', $attempt, json_encode($answers, JSON_THROW_ON_ERROR));
            $statuses = array_column($answers, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, $context);
            self::assertSame('{"error":{"code":"invalid_transition","fields":[]}}', $answers[$answers[0]['status'] === 409 ? 0 : 1]['body'], $context);

            $winner = $answers[0]['status'] === 200 ? 'approve' : 'cancel';
            ++$winners[$winner];
            self::assertSame($winner === 'approve' ? 'completed' : 'cancelled', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]), $context);
            self::assertSame(
                [$winner === 'approve' ? 'task_approved' : 'task_cancelled'],
                array_column($this->fetchRows('SELECT type FROM task_events WHERE task_id = ?', [$id]), 'type'),
                $context,
            );
            self::assertSame(
                [['ended_how' => $winner === 'approve' ? 'completed' : 'cancelled']],
                $this->fetchRows('SELECT ended_how FROM assignments WHERE task_id = ?', [$id]),
                $context,
            );
            self::assertSame($winner === 'approve' ? 'approved' : null, $this->fetchValue('SELECT decision FROM rounds WHERE task_id = ?', [$id]), $context);
        }

        self::assertSame(self::ATTEMPTS, $winners['approve'] + $winners['cancel']);
    }

    public function test_double_approve_creates_exactly_one_completion(): void
    {
        for ($attempt = 0; $attempt < self::ATTEMPTS; ++$attempt) {
            $id = $this->taskInStatus(TaskStatus::UnderReview);
            $request = $this->post($id, 'approve', []);

            $answers = RaceRunner::run($this->workDir, [$request, $request]);

            $statuses = array_column($answers, 'status');
            sort($statuses);
            self::assertSame([200, 409], $statuses, sprintf('Attempt %d: %s', $attempt, json_encode($answers, JSON_THROW_ON_ERROR)));
            self::assertSame(1, $this->fetchValue("SELECT COUNT(*) FROM task_events WHERE task_id = ? AND type = 'task_approved'", [$id]));
            self::assertSame(1, $this->fetchValue('SELECT COUNT(*) FROM assignments WHERE task_id = ? AND ended_how = ?', [$id, 'completed']));
        }
    }

    public function test_approve_racing_request_revisions_has_exactly_one_winner(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        $answers = RaceRunner::run($this->workDir, [
            $this->post($id, 'approve', []),
            $this->post($id, 'request-revisions', ['reason' => 'Again']),
        ]);

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame([200, 409], $statuses, json_encode($answers, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->countRows('task_events'));
        self::assertNotNull($this->fetchValue('SELECT decision FROM rounds'));
    }

    /**
     * @param array<string, mixed> $body
     * @return array{userId: int, method: string, path: string, body: array<string, mixed>}
     */
    private function post(int $taskId, string $action, array $body): array
    {
        return ['userId' => $this->anna->id, 'method' => 'POST', 'path' => '/api/tasks/' . $taskId . '/' . $action, 'body' => $body];
    }
}
