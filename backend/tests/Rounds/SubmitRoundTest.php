<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Identity\Role;
use App\Tasks\TaskStatus;
use DateTimeImmutable;
use DateTimeZone;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * POST /api/tasks/{id}/rounds: the assigned designer hands in a round of deliverables. The status
 * change, the round, its deliverables, the attachment of the files and the round_submitted event
 * change together or not at all.
 *
 * Order of the answers: no such task or not viewable -> 404; viewable but not the assigned designer
 * -> 403; the task is in a status that takes no submission -> 409 invalid_transition; a bad body or
 * bad files -> 422.
 */
final class SubmitRoundTest extends RoundsTestCase
{
    public function test_first_submission_creates_round_1_and_status_under_review(): void
    {
        $id = $this->taskOfDave();
        $first = $this->uploadFor($this->dave, 'one.png');
        $second = $this->uploadFor($this->dave, 'два 🎰.png');
        $this->clock->set(new DateTimeImmutable('2026-01-15 11:45:30.250000', new DateTimeZone('UTC')));

        $response = $this->submit($id, [$first, $second], extra: ['note' => "Первый вариант 🎰 <b>bold</b>\nline 2"]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('{"number":1}', (string) $response->getBody());

        self::assertSame(
            [['status' => 'under_review', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 11:45:30']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );

        $rounds = $this->fetchRows('SELECT task_id, number, submitted_by, submitted_at, note, decision, decision_reason, decided_at FROM rounds');
        self::assertSame(
            [[
                'task_id' => $id,
                'number' => 1,
                'submitted_by' => $this->dave->id,
                'submitted_at' => '2026-01-15 11:45:30.250000',
                'note' => "Первый вариант 🎰 <b>bold</b>\nline 2",
                'decision' => null,
                'decision_reason' => null,
                'decided_at' => null,
            ]],
            $rounds,
        );
        self::assertSame([$first, $second], array_column($this->fetchRows('SELECT file_id FROM deliverables ORDER BY id'), 'file_id'));
        self::assertSame(
            [['task_id' => $id, 'kind' => 'deliverable'], ['task_id' => $id, 'kind' => 'deliverable']],
            $this->fetchRows('SELECT task_id, kind FROM file_attachments ORDER BY file_id'),
        );

        $events = $this->fetchRows('SELECT type, actor_id, at, payload FROM task_events');
        self::assertCount(1, $events);
        self::assertSame('round_submitted', $events[0]['type']);
        self::assertSame($this->dave->id, $events[0]['actor_id']);
        self::assertSame('2026-01-15 11:45:30.250000', $events[0]['at']);
        self::assertSame(['round' => 1, 'fileCount' => 2], json_decode(self::text($events[0]['payload']), true));
    }

    public function test_the_detail_shows_the_round_and_the_new_status(): void
    {
        $id = $this->taskOfDave();
        $first = $this->uploadFor($this->dave, 'one.png');
        $second = $this->uploadFor($this->dave, 'two.png');
        $this->clock->set(new DateTimeImmutable('2026-01-15 11:45:30.250000', new DateTimeZone('UTC')));
        $this->submit($id, [$first, $second], extra: ['note' => 'v1']);

        foreach ([$this->dave, $this->anna, $this->mia] as $viewer) {
            $detail = $this->request('GET', '/api/tasks/' . $id, as: $viewer);

            self::assertSame(200, $detail->getStatusCode());
            $this->assertMatchesSpec($detail);
            self::assertSame('under_review', self::jsonAt($detail, 'status'));
            self::assertSame(1, self::jsonAt($detail, 'roundCount'));
            self::assertSame('0.00', self::jsonAt($detail, 'creditsTotal'));
            self::assertFalse(self::jsonAt($detail, 'isReturning'));
            $rounds = self::jsonArray($detail, 'rounds');
            self::assertCount(1, $rounds);
            self::assertSame(
                [
                    'number' => 1,
                    'submittedAt' => '2026-01-15T11:45:30.250Z',
                    'submittedBy' => ['id' => $this->dave->id, 'displayName' => 'Dave Test'],
                    'note' => 'v1',
                    'files' => [
                        ['id' => $first, 'name' => 'one.png', 'size' => $this->fetchValue('SELECT size FROM files WHERE id = ?', [$first]), 'mime' => 'image/png'],
                        ['id' => $second, 'name' => 'two.png', 'size' => $this->fetchValue('SELECT size FROM files WHERE id = ?', [$second]), 'mime' => 'image/png'],
                    ],
                    'decision' => null,
                    'decisionReason' => null,
                    'decidedAt' => null,
                    'aiUsage' => [],
                ],
                $rounds[0],
            );
        }

        $designer = $this->request('GET', '/api/tasks/' . $id, as: $this->dave);
        self::assertSame(['report_ai_usage'], self::jsonAt($designer, 'allowedActions'));
        $buyer = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        self::assertSame(['edit_brief', 'approve', 'request_revisions', 'cancel'], self::jsonAt($buyer, 'allowedActions'));
    }

    public function test_resubmission_from_revisions_required_creates_round_2_keeping_round_1_files(): void
    {
        $id = $this->taskOfDave(TaskStatus::RevisionsRequired);
        $old = $this->uploadFor($this->dave, 'v1.png');
        // Round 1 was sent back (Task 13 will write the decision; here it is inserted directly).
        $this->insertRound($id, 1, [$old], 'revisions_requested', 'Logo is too small');
        $new = $this->uploadFor($this->dave, 'v2.png');

        $response = $this->submit($id, [$new]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(2, self::jsonAt($response, 'number'));
        self::assertSame('under_review', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
        self::assertSame([1, 2], array_column($this->fetchRows('SELECT number FROM rounds WHERE task_id = ? ORDER BY number', [$id]), 'number'));

        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        $this->assertMatchesSpec($detail);
        $rounds = self::jsonArray($detail, 'rounds');
        self::assertCount(2, $rounds);
        self::assertSame(2, self::jsonAt($detail, 'roundCount'));
        self::assertSame([1, 2], [self::jsonAt($detail, 'rounds.0.number'), self::jsonAt($detail, 'rounds.1.number')]);
        self::assertSame('revisions_requested', self::jsonAt($detail, 'rounds.0.decision'));
        self::assertSame('Logo is too small', self::jsonAt($detail, 'rounds.0.decisionReason'));
        self::assertSame('2026-01-10T11:00:00.500Z', self::jsonAt($detail, 'rounds.0.decidedAt'));
        self::assertSame([$old], array_column(self::jsonArray($detail, 'rounds.0.files'), 'id'));
        self::assertSame([$new], array_column(self::jsonArray($detail, 'rounds.1.files'), 'id'));
        self::assertNull(self::jsonAt($detail, 'rounds.1.decision'));

        $event = $this->fetchRows("SELECT payload FROM task_events WHERE type = 'round_submitted'");
        self::assertSame(['round' => 2, 'fileCount' => 1], json_decode(self::text($event[0]['payload']), true));
    }

    public function test_exactly_25_files_are_accepted_and_a_note_may_be_absent_or_2000_characters(): void
    {
        $id = $this->taskOfDave();
        $ids = [];
        for ($i = 0; $i < 25; ++$i) {
            $ids[] = $this->uploadFor($this->dave, 'f' . $i . '.png');
        }

        $response = $this->submit($id, $ids, extra: ['note' => str_repeat('Ж', 2000)]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(25, $this->countRows('deliverables'));
        self::assertSame(str_repeat('Ж', 2000), $this->fetchValue('SELECT note FROM rounds'));

        $other = $this->taskOfDave();
        $single = $this->uploadFor($this->dave);
        self::assertSame(201, $this->submit($other, [$single])->getStatusCode());
        self::assertNull($this->fetchValue('SELECT note FROM rounds WHERE task_id = ?', [$other]));
    }

    public function test_an_empty_note_is_stored_as_no_note(): void
    {
        $id = $this->taskOfDave();

        self::assertSame(201, $this->submit($id, [$this->uploadFor($this->dave)], extra: ['note' => ''])->getStatusCode());
        self::assertNull($this->fetchValue('SELECT note FROM rounds'));
    }

    /**
     * @param array<string, mixed> $body
     */
    #[DataProvider('missingFiles')]
    public function test_zero_files_is_422_required(array $body): void
    {
        $id = $this->taskOfDave();

        $response = $this->request('POST', '/api/tasks/' . $id . '/rounds', $body, $this->dave);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
        self::assertSame([['field' => 'fileIds', 'code' => 'required', 'params' => []]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
    }

    /**
     * @return array<string, array{array<string, mixed>}>
     */
    public static function missingFiles(): array
    {
        return [
            'an empty list' => [['fileIds' => []]],
            'no fileIds' => [['note' => 'hello']],
        ];
    }

    public function test_an_empty_body_is_422_required(): void
    {
        $id = $this->taskOfDave();

        $response = $this->request('POST', '/api/tasks/' . $id . '/rounds', as: $this->dave);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame([['field' => 'fileIds', 'code' => 'required', 'params' => []]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
    }

    public function test_26_files_is_422_too_many_with_max_25(): void
    {
        $id = $this->taskOfDave();
        $ids = [];
        for ($i = 0; $i < 26; ++$i) {
            $ids[] = $this->uploadFor($this->dave);
        }

        $response = $this->submit($id, $ids);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame([['field' => 'fileIds', 'code' => 'too_many', 'params' => ['max' => 25]]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
        self::assertSame(0, $this->countRows('file_attachments'));
    }

    /**
     * @param list<mixed> $fileIds
     */
    #[DataProvider('malformedFileIds')]
    public function test_file_ids_must_be_positive_integers(array $fileIds, string $field): void
    {
        $id = $this->taskOfDave();

        $response = $this->submit($id, $fileIds);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame([['field' => $field, 'code' => 'invalid', 'params' => []]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
    }

    /**
     * @return array<string, array{list<mixed>, string}>
     */
    public static function malformedFileIds(): array
    {
        return [
            'a numeric string' => [['5'], 'fileIds.0'],
            'a float' => [[5.5], 'fileIds.0'],
            'a boolean' => [[true], 'fileIds.0'],
            'null' => [[null], 'fileIds.0'],
            'zero' => [[0], 'fileIds.0'],
            'a negative number' => [[-3], 'fileIds.0'],
            'a nested list' => [[[1]], 'fileIds.0'],
        ];
    }

    public function test_file_ids_must_be_a_list(): void
    {
        $id = $this->taskOfDave();

        $response = $this->request('POST', '/api/tasks/' . $id . '/rounds', ['fileIds' => 'abc'], $this->dave);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([['field' => 'fileIds', 'code' => 'invalid', 'params' => []]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
    }

    public function test_duplicate_file_ids_are_422(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);

        $response = $this->submit($id, [$file, $file]);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame([['field' => 'files', 'code' => 'invalid_choice', 'params' => []]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
        self::assertSame(0, $this->countRows('file_attachments'));
    }

    public function test_a_note_of_2001_characters_is_too_long(): void
    {
        $id = $this->taskOfDave();

        $response = $this->submit($id, [$this->uploadFor($this->dave)], extra: ['note' => str_repeat('Ж', 2001)]);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame([['field' => 'note', 'code' => 'too_long', 'params' => ['max' => 2000]]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingChanged($id);
    }

    public function test_a_note_must_be_a_string_and_unknown_fields_are_refused(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);

        $note = $this->submit($id, [$file], extra: ['note' => 5]);
        self::assertSame(422, $note->getStatusCode());
        self::assertSame([['field' => 'note', 'code' => 'invalid', 'params' => []]], self::jsonAt($note, 'error.fields'));

        $unknown = $this->submit($id, [$file], extra: ['status' => 'completed']);
        self::assertSame(422, $unknown->getStatusCode());
        self::assertSame([['field' => 'status', 'code' => 'unknown_field', 'params' => []]], self::jsonAt($unknown, 'error.fields'));
        $this->assertNothingChanged($id);
    }

    public function test_files_must_be_owned_and_unattached(): void
    {
        $id = $this->taskOfDave();
        $evesFile = $this->uploadFor($this->eve);
        $attached = $this->uploadFor($this->dave);
        $reference = $this->insertTask($this->anna, 'Other');
        $this->database->pdo()->prepare("INSERT INTO file_attachments (file_id, task_id, kind) VALUES (?, ?, 'reference')")->execute([$attached, $reference]);
        $own = $this->uploadFor($this->dave);

        foreach (['somebody else\'s' => $evesFile, 'already attached' => $attached, 'unknown' => 999999] as $what => $bad) {
            $response = $this->submit($id, [$own, $bad]);

            self::assertSame(422, $response->getStatusCode(), $what);
            $this->assertMatchesSpec($response);
            self::assertSame('validation_failed', self::jsonAt($response, 'error.code'), $what);
            self::assertSame([['field' => 'files', 'code' => 'invalid_choice', 'params' => []]], self::jsonAt($response, 'error.fields'), $what);
            // Whole round or nothing: the valid file in the same request was not attached either.
            $this->assertNothingChanged($id);
            self::assertSame(1, $this->countRows('file_attachments'), $what);
        }

        self::assertSame(201, $this->submit($id, [$own])->getStatusCode());
    }

    public function test_a_file_already_delivered_cannot_be_delivered_again(): void
    {
        $first = $this->taskOfDave();
        $second = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        self::assertSame(201, $this->submit($first, [$file])->getStatusCode());

        $response = $this->submit($second, [$file]);

        self::assertSame(422, $response->getStatusCode());
        self::assertSame([['field' => 'files', 'code' => 'invalid_choice', 'params' => []]], self::jsonAt($response, 'error.fields'));
        self::assertSame(1, $this->countRows('deliverables'));
        self::assertSame('in_progress', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$second]));
    }

    public function test_a_designer_who_is_not_assigned_is_403_on_a_new_task_and_404_on_one_in_progress(): void
    {
        $new = $this->insertTask($this->anna);
        $held = $this->taskOfDave();
        $file = $this->uploadFor($this->eve);

        // Eve may see a task in the pool but it is not hers to submit to.
        $onNew = $this->submit($new, [$file], $this->eve);
        self::assertSame(403, $onNew->getStatusCode());
        $this->assertMatchesSpec($onNew);
        self::assertSame('forbidden', self::jsonAt($onNew, 'error.code'));

        // She cannot see Dave's task at all.
        $onHeld = $this->submit($held, [$file], $this->eve);
        self::assertSame(404, $onHeld->getStatusCode());
        $this->assertMatchesSpec($onHeld);
        self::assertSame('not_found', self::jsonAt($onHeld, 'error.code'));

        self::assertSame(404, $this->submit(999999, [$file], $this->eve)->getStatusCode());
        self::assertSame(0, $this->countRows('rounds'));
        self::assertSame(0, $this->countRows('file_attachments'));
    }

    public function test_buyer_and_manager_403_and_another_buyer_404(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->anna);

        foreach ([$this->anna, $this->mia] as $viewer) {
            $response = $this->submit($id, [$file], $viewer);

            self::assertSame(403, $response->getStatusCode(), $viewer->username);
            $this->assertMatchesSpec($response);
            self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        }

        $otto = $this->createUser('otto', Role::Buyer);
        self::assertSame(404, $this->submit($id, [$file], $otto)->getStatusCode());
        $this->assertNothingChanged($id);
    }

    public function test_permission_is_checked_before_the_body(): void
    {
        $id = $this->taskOfDave();

        self::assertSame(403, $this->submit($id, [], $this->anna)->getStatusCode());
        self::assertSame(404, $this->submit($id, [], $this->eve)->getStatusCode());
    }

    public function test_submit_from_new_is_409(): void
    {
        // A New task with an assignee does not arise through the API; the state rule still refuses it.
        $id = $this->insertTask($this->anna, assignee: $this->dave);
        $file = $this->uploadFor($this->dave);

        $response = $this->submit($id, [$file]);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        self::assertSame(0, $this->countRows('rounds'));
        self::assertSame(0, $this->countRows('file_attachments'));
        self::assertSame('new', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
    }

    #[DataProvider('statusesThatTakeNoSubmission')]
    public function test_submit_from_any_other_status_is_409(TaskStatus $status): void
    {
        $id = $this->taskOfDave($status);
        $file = $this->uploadFor($this->dave);

        $response = $this->submit($id, [$file]);

        self::assertSame(409, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        self::assertSame(0, $this->countRows('rounds'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(0, $this->countRows('file_attachments'));
        self::assertSame($status->value, $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
    }

    /**
     * @return array<string, array{TaskStatus}>
     */
    public static function statusesThatTakeNoSubmission(): array
    {
        return [
            'under review' => [TaskStatus::UnderReview],
            'completed' => [TaskStatus::Completed],
            'cancelled' => [TaskStatus::Cancelled],
        ];
    }

    public function test_the_state_rule_comes_before_the_body_check(): void
    {
        $id = $this->taskOfDave(TaskStatus::UnderReview);

        self::assertSame(409, $this->submit($id, [])->getStatusCode());
    }

    public function test_a_designer_who_released_the_task_cannot_submit(): void
    {
        $id = $this->insertTask($this->anna);
        $file = $this->uploadFor($this->dave);
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $this->dave)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/release', as: $this->dave)->getStatusCode());

        $response = $this->submit($id, [$file]);

        // The task is in the pool again: Dave may see it, but it is not his.
        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        self::assertSame(0, $this->countRows('rounds'));
        self::assertSame(0, $this->countRows('file_attachments'));

        // And once another designer holds it, it does not exist for him.
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $this->eve)->getStatusCode());
        self::assertSame(404, $this->submit($id, [$file])->getStatusCode());
    }

    public function test_a_second_click_after_the_first_is_409_and_still_one_round(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        $first = $this->submit($id, [$file]);
        self::assertSame(201, $first->getStatusCode());

        $second = $this->submit($id, [$file]);

        self::assertSame(409, $second->getStatusCode());
        $this->assertMatchesSpec($second);
        self::assertSame('invalid_transition', self::jsonAt($second, 'error.code'));
        self::assertSame(1, $this->countRows('rounds'));
        self::assertSame(1, $this->countRows('deliverables'));
        self::assertSame(1, $this->countRows('task_events'));
    }

    public function test_rollback_leaves_everything_unchanged_when_the_event_cannot_be_recorded(): void
    {
        $id = $this->taskOfDave();
        $files = [$this->uploadFor($this->dave), $this->uploadFor($this->dave)];
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TRIGGER fail_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

        try {
            $response = $this->submit($id, $files, extra: ['note' => 'x']);
        } finally {
            $pdo->exec('DROP TRIGGER fail_events');
        }

        self::assertSame(500, $response->getStatusCode());
        $this->assertNothingChanged($id);
        self::assertSame(0, $this->countRows('file_attachments'));

        // The same request goes through once the fault is gone: nothing was half done.
        self::assertSame(201, $this->submit($id, $files)->getStatusCode());
        self::assertSame(1, self::jsonAt($this->request('GET', '/api/tasks/' . $id, as: $this->dave), 'roundCount'));
    }

    public function test_rollback_when_the_status_cannot_be_changed(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        $pdo = $this->database->pdo();
        // Something that ignores the row lock changes the status before the guarded UPDATE.
        $pdo->exec('CREATE TRIGGER keep_status BEFORE UPDATE ON tasks FOR EACH ROW SET NEW.status = OLD.status, NEW.updated_at = OLD.updated_at');

        try {
            $response = $this->submit($id, [$file]);
        } finally {
            $pdo->exec('DROP TRIGGER keep_status');
        }

        self::assertSame(409, $response->getStatusCode());
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        $this->assertNothingChanged($id);
        self::assertSame(0, $this->countRows('file_attachments'));
    }

    public function test_submit_needs_the_csrf_token(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        [$token] = $this->startSession($this->dave);

        $response = $this->handle(self::withSession($this->buildRequest('POST', '/api/tasks/' . $id . '/rounds', ['fileIds' => [$file]]), $token));

        self::assertSame(403, $response->getStatusCode());
        self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        $this->assertNothingChanged($id);
    }

    public function test_submit_needs_a_session(): void
    {
        $id = $this->taskOfDave();

        $response = $this->handle($this->buildRequest('POST', '/api/tasks/' . $id . '/rounds', ['fileIds' => [1]]));

        self::assertSame(401, $response->getStatusCode());
    }

    private function assertNothingChanged(int $id): void
    {
        self::assertSame(0, $this->countRows('rounds'));
        self::assertSame(0, $this->countRows('deliverables'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame(0, $this->fetchValue("SELECT COUNT(*) FROM file_attachments WHERE kind = 'deliverable'"));
        self::assertSame(
            [['status' => 'in_progress', 'assignee_id' => $this->dave->id, 'updated_at' => '2026-01-15 09:00:00']],
            $this->fetchRows('SELECT status, assignee_id, updated_at FROM tasks WHERE id = ?', [$id]),
        );
    }
}
