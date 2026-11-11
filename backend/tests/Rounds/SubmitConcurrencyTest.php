<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Tests\Support\RaceRunner;

/**
 * A double-click on Submit, in its worst form: two requests for the same task at the same instant,
 * in two real operating system processes with a connection each (see RaceRunner). Exactly one
 * round is recorded; the other request is told the task is no longer open for a submission.
 */
final class SubmitConcurrencyTest extends RoundsTestCase
{
    private const ROUNDS = 15;

    public function test_double_submit_creates_exactly_one_round(): void
    {
        for ($attempt = 0; $attempt < self::ROUNDS; ++$attempt) {
            $taskId = $this->taskOfDave();
            $files = [$this->uploadFor($this->dave), $this->uploadFor($this->dave)];
            $request = [
                'userId' => $this->dave->id,
                'method' => 'POST',
                'path' => '/api/tasks/' . $taskId . '/rounds',
                'body' => ['fileIds' => $files, 'note' => 'try ' . $attempt],
            ];

            // The same files sent twice: whichever request is second must not win, by the status or by the files.
            $answers = RaceRunner::run($this->workDir, [$request, $request]);

            $statuses = array_column($answers, 'status');
            sort($statuses);
            self::assertSame([201, 409], $statuses, sprintf('Attempt %d: %s', $attempt, json_encode($answers, JSON_THROW_ON_ERROR)));
            foreach ($answers as $answer) {
                $body = json_decode($answer['body'], true, 512, JSON_THROW_ON_ERROR);
                self::assertSame(
                    $answer['status'] === 201 ? ['number' => 1] : ['error' => ['code' => 'invalid_transition', 'fields' => []]],
                    $body,
                );
            }

            self::assertSame(1, $this->fetchValue('SELECT COUNT(*) FROM rounds WHERE task_id = ?', [$taskId]), sprintf('Attempt %d: not exactly one round.', $attempt));
            self::assertSame(2, $this->fetchValue('SELECT COUNT(*) FROM deliverables d JOIN rounds r ON r.id = d.round_id WHERE r.task_id = ?', [$taskId]));
            self::assertSame(1, $this->fetchValue("SELECT COUNT(*) FROM task_events WHERE task_id = ? AND type = 'round_submitted'", [$taskId]));
            self::assertSame('under_review', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$taskId]));
        }

        self::assertSame(self::ROUNDS, $this->countRows('rounds'));
    }

    public function test_two_submits_with_different_files_still_make_one_round(): void
    {
        $taskId = $this->taskOfDave();
        $requests = [];
        for ($i = 0; $i < 2; ++$i) {
            $requests[] = [
                'userId' => $this->dave->id,
                'method' => 'POST',
                'path' => '/api/tasks/' . $taskId . '/rounds',
                'body' => ['fileIds' => [$this->uploadFor($this->dave)]],
            ];
        }

        $answers = RaceRunner::run($this->workDir, $requests);

        $statuses = array_column($answers, 'status');
        sort($statuses);
        self::assertSame([201, 409], $statuses, json_encode($answers, JSON_THROW_ON_ERROR));
        self::assertSame(1, $this->countRows('rounds'));
        self::assertSame(1, $this->countRows('deliverables'));
        self::assertSame(1, $this->fetchValue("SELECT COUNT(*) FROM file_attachments WHERE kind = 'deliverable'"));
    }
}
