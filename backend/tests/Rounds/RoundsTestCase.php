<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Files\Samples;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use DateTimeZone;
use Psr\Http\Message\ResponseInterface;

/**
 * Shared set-up of the tests of rounds: a buyer, two designers and a manager, and helpers that
 * upload deliverables and put tasks into the states a round needs.
 */
abstract class RoundsTestCase extends ApiTestCase
{
    protected User $anna;

    protected User $dave;

    protected User $eve;

    protected User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', Role::Buyer);
        $this->dave = $this->createUser('dave', Role::Designer);
        $this->eve = $this->createUser('eve', Role::Designer);
        $this->mia = $this->createUser('mia', Role::Manager);
    }

    /**
     * A task of Anna's that Dave holds, by default in progress.
     */
    protected function taskOfDave(TaskStatus $status = TaskStatus::InProgress): int
    {
        return $this->insertTask($this->anna, status: $status, assignee: $this->dave);
    }

    /**
     * Uploads a real file for $owner and returns its id.
     */
    protected function uploadFor(User $owner, string $name = 'banner.png'): int
    {
        $response = $this->upload(Samples::png(), $name, as: $owner);
        $id = self::jsonAt($response, 'id');
        self::assertIsInt($id, (string) $response->getBody());

        return $id;
    }

    /**
     * @param list<mixed> $fileIds
     * @param array<string, mixed> $extra further fields of the body
     */
    protected function submit(int $taskId, array $fileIds, ?User $as = null, array $extra = []): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $taskId . '/rounds', ['fileIds' => $fileIds, ...$extra], $as ?? $this->dave);
    }

    /**
     * Inserts a round of $taskId straight into the table, as Task 13 will leave it after a decision,
     * with the given files as its deliverables (their rows must exist already).
     *
     * @param list<int> $fileIds
     * @return int the round id
     */
    protected function insertRound(int $taskId, int $number, array $fileIds = [], ?string $decision = null, ?string $reason = null, string $submittedAt = '2026-01-10 10:00:00.500000'): int
    {
        $pdo = $this->database->pdo();
        $decidedAt = $decision === null ? null : (new DateTimeImmutable($submittedAt, new DateTimeZone('UTC')))->modify('+1 hour')->format('Y-m-d H:i:s.u');
        $pdo->prepare(
            'INSERT INTO rounds (task_id, number, submitted_by, submitted_at, note, decision, decision_reason, decided_at) VALUES (?, ?, ?, ?, NULL, ?, ?, ?)',
        )->execute([$taskId, $number, $this->dave->id, $submittedAt, $decision, $reason, $decidedAt]);
        $roundId = (int) $pdo->lastInsertId();

        foreach ($fileIds as $fileId) {
            $pdo->prepare("INSERT INTO file_attachments (file_id, task_id, kind) VALUES (?, ?, 'deliverable')")->execute([$fileId, $taskId]);
            $pdo->prepare('INSERT INTO deliverables (round_id, file_id) VALUES (?, ?)')->execute([$roundId, $fileId]);
        }

        return $roundId;
    }

    /**
     * Opens an assignment of $taskId to $designer straight in the table (the task row is not touched).
     */
    protected function openAssignment(int $taskId, ?User $designer = null): void
    {
        $this->database->pdo()->prepare('INSERT INTO assignments (task_id, designer_id, assigned_at) VALUES (?, ?, ?)')
            ->execute([$taskId, ($designer ?? $this->dave)->id, $this->clock->now()->format('Y-m-d H:i:s.u')]);
    }

    /**
     * A task of Anna's that Dave holds in the given status, with the open assignment such a task has.
     * From under review on it also has round 1, undecided, with one deliverable.
     */
    protected function taskInStatus(TaskStatus $status): int
    {
        if ($status === TaskStatus::New) {
            return $this->insertTask($this->anna);
        }

        $id = $this->taskOfDave($status);
        $this->openAssignment($id);
        if ($status === TaskStatus::UnderReview) {
            $this->insertRound($id, 1, [$this->uploadFor($this->dave)]);
        }

        return $id;
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function approve(int $taskId, ?User $as = null, array $body = []): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $taskId . '/approve', $body, $as ?? $this->anna);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function requestRevisions(int $taskId, ?User $as = null, array $body = ['reason' => 'Make the logo bigger']): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $taskId . '/request-revisions', $body, $as ?? $this->anna);
    }

    /**
     * @param array<string, mixed> $body
     */
    protected function cancel(int $taskId, ?User $as = null, array $body = []): ResponseInterface
    {
        return $this->request('POST', '/api/tasks/' . $taskId . '/cancel', $body, $as ?? $this->anna);
    }

    protected static function text(mixed $value): string
    {
        return is_string($value) ? $value : self::fail('Expected a string column.');
    }
}
