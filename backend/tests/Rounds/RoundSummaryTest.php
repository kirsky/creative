<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Identity\User;
use App\Tasks\RoundReader;
use App\Tasks\TaskStatus;

/**
 * What the board card says about a task's rounds: how many there were, the AI credits (the sum of
 * the usage lines, see tests/AiUsage) and whether the task is "returning", that is, back in the pool because
 * the latest round was sent back for revisions.
 */
final class RoundSummaryTest extends RoundsTestCase
{
    public function test_counts_rounds_per_task_in_one_batch(): void
    {
        $none = $this->insertTask($this->anna, 'None');
        $one = $this->taskOfDave(TaskStatus::UnderReview);
        $two = $this->taskOfDave(TaskStatus::RevisionsRequired);
        $this->insertRound($one, 1);
        $this->insertRound($two, 1, decision: 'revisions_requested', reason: 'Again');
        $this->insertRound($two, 2);

        $summaries = $this->service(RoundReader::class)->summaries([$none, $one, $two]);

        self::assertSame(1, $summaries[$one]->roundCount ?? null);
        self::assertSame(2, $summaries[$two]->roundCount ?? null);
        self::assertSame('0.00', $summaries[$two]->creditsTotal ?? null);
        self::assertArrayNotHasKey($none, $summaries);
        self::assertSame([], $this->service(RoundReader::class)->summaries([]));
    }

    public function test_the_board_shows_round_count_and_returning(): void
    {
        $returning = $this->insertTask($this->anna, 'Back', deadline: '2026-02-01 10:00:00');
        $this->insertRound($returning, 1, decision: 'revisions_requested', reason: 'Logo');
        $fresh = $this->insertTask($this->anna, 'Fresh', deadline: '2026-02-02 10:00:00');

        $response = $this->request('GET', '/api/tasks', as: $this->dave);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame($returning, self::jsonAt($response, 'tasks.0.id'));
        self::assertSame(1, self::jsonAt($response, 'tasks.0.roundCount'));
        self::assertTrue(self::jsonAt($response, 'tasks.0.isReturning'));
        self::assertSame('0.00', self::jsonAt($response, 'tasks.0.creditsTotal'));
        self::assertSame($fresh, self::jsonAt($response, 'tasks.1.id'));
        self::assertSame(0, self::jsonAt($response, 'tasks.1.roundCount'));
        self::assertFalse(self::jsonAt($response, 'tasks.1.isReturning'));
    }

    public function test_returning_needs_status_new_and_the_latest_round_sent_back(): void
    {
        $reader = $this->service(RoundReader::class);

        // Latest round sent back, task in the pool again: returning.
        $released = $this->insertTask($this->anna, 'Released');
        $this->insertRound($released, 1, decision: 'revisions_requested');
        // An earlier round was sent back but the latest was approved or is undecided: not returning.
        $approved = $this->insertTask($this->anna, 'Approved later');
        $this->insertRound($approved, 1, decision: 'revisions_requested');
        $this->insertRound($approved, 2, decision: 'approved');
        $undecided = $this->insertTask($this->anna, 'Undecided later');
        $this->insertRound($undecided, 1, decision: 'revisions_requested');
        $this->insertRound($undecided, 2);
        // Sent back, but it is with its designer, not back in the pool.
        $inRevision = $this->taskOfDave(TaskStatus::RevisionsRequired);
        $this->insertRound($inRevision, 1, decision: 'revisions_requested');
        // Never submitted.
        $plain = $this->insertTask($this->anna, 'Plain');

        $summaries = $reader->summaries([$released, $approved, $undecided, $inRevision, $plain]);

        self::assertTrue($summaries[$released]->isReturning ?? false);
        self::assertFalse($summaries[$approved]->isReturning ?? true);
        self::assertFalse($summaries[$undecided]->isReturning ?? true);
        self::assertFalse($summaries[$inRevision]->isReturning ?? true);
        self::assertArrayNotHasKey($plain, $summaries);
    }

    public function test_the_latest_round_is_the_highest_number_not_the_last_inserted(): void
    {
        $id = $this->insertTask($this->anna);
        $this->insertRound($id, 2, decision: 'revisions_requested');
        $this->insertRound($id, 1, decision: 'approved');

        $summary = $this->service(RoundReader::class)->summaries([$id])[$id] ?? null;

        self::assertNotNull($summary);
        self::assertTrue($summary->isReturning);
        self::assertSame(2, $summary->roundCount);
    }

    public function test_a_released_task_comes_back_as_returning_through_the_api(): void
    {
        $id = $this->taskOfDave(TaskStatus::RevisionsRequired);
        $this->insertRound($id, 1, decision: 'revisions_requested', reason: 'Fix it');
        $this->database->pdo()->prepare('INSERT INTO assignments (task_id, designer_id, assigned_at) VALUES (?, ?, ?)')
            ->execute([$id, $this->dave->id, '2026-01-10 09:00:00.000000']);

        $released = $this->request('POST', '/api/tasks/' . $id . '/release', as: $this->dave);

        self::assertSame(200, $released->getStatusCode());
        $this->assertMatchesSpec($released);
        self::assertSame('new', self::jsonAt($released, 'status'));
        self::assertTrue(self::jsonAt($released, 'isReturning'));
        self::assertSame(1, self::jsonAt($released, 'roundCount'));
        self::assertSame(1, count(self::jsonArray($released, 'rounds')));
    }

    public function test_released_after_revision_request_is_returning(): void
    {
        $id = $this->taskOfDave();
        $this->openAssignment($id);
        self::assertSame(201, $this->submit($id, [$this->uploadFor($this->dave)])->getStatusCode());
        self::assertSame(200, $this->requestRevisions($id)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/release', as: $this->dave)->getStatusCode());

        // The card on the board, for the designers and for the buyer who sent it back.
        foreach ([$this->eve, $this->dave, $this->anna] as $viewer) {
            $card = $this->cardOf($id, $viewer);
            self::assertSame('new', $card['status'] ?? null, $viewer->username);
            self::assertTrue($card['isReturning'] ?? null, $viewer->username);
            self::assertSame(1, $card['roundCount'] ?? null, $viewer->username);
        }

        // Another designer takes it up and hands in round 2: it is no longer a task coming back.
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $this->eve)->getStatusCode());
        $returning = $this->cardOf($id, $this->eve);
        self::assertSame('in_progress', $returning['status'] ?? null);
        self::assertFalse($returning['isReturning'] ?? null);
        self::assertSame(201, $this->submit($id, [$this->uploadFor($this->eve)], $this->eve)->getStatusCode());

        $card = $this->cardOf($id, $this->eve);
        self::assertSame('under_review', $card['status'] ?? null);
        self::assertFalse($card['isReturning'] ?? null);
        self::assertSame(2, $card['roundCount'] ?? null);
    }

    /**
     * @return array<array-key, mixed> the board card of the task, as the viewer gets it
     */
    private function cardOf(int $taskId, User $viewer): array
    {
        $response = $this->request('GET', '/api/tasks', as: $viewer);
        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);

        foreach (self::jsonArray($response, 'tasks') as $card) {
            if (is_array($card) && ($card['id'] ?? null) === $taskId) {
                return $card;
            }
        }

        self::fail(sprintf('%s does not get task %d on the board.', $viewer->username, $taskId));
    }
}
