<?php

declare(strict_types=1);

namespace App\Tests\AiUsage;

use App\Tasks\TaskStatus;

/**
 * PUT /api/tasks/{id}/rounds/{n}/ai-usage: the designer who submitted a round replaces its lines
 * until the buyer decides it. Order of the answers: no such task or not viewable -> 404; viewable but
 * not the task's assigned designer -> 403; no such round -> 404; a round of another designer -> 403;
 * decided round (or a task that takes no usage any more) -> 409 invalid_transition; bad lines -> 422.
 */
final class ReplaceAiUsageTest extends AiUsageTestCase
{
    public function test_editable_until_decision_then_409(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');
        $this->clock->set(new \DateTimeImmutable('2026-01-15 12:00:00.125', new \DateTimeZone('UTC')));

        $response = $this->replace($id, 1, [['provider' => 'runway', 'credits' => '3.5'], ['provider' => 'Kling', 'credits' => '1']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(
            [['provider' => 'runway', 'credits' => '3.50'], ['provider' => 'Kling', 'credits' => '1.00']],
            self::jsonAt($response, 'rounds.0.aiUsage'),
        );
        self::assertSame('4.50', self::jsonAt($response, 'creditsTotal'));
        self::assertSame($id, self::jsonAt($response, 'id'));
        self::assertSame('under_review', self::jsonAt($response, 'status'));
        self::assertSame(
            [['provider' => 'runway', 'credits' => '3.50'], ['provider' => 'Kling', 'credits' => '1.00']],
            $this->storedLines($id, 1),
        );
        self::assertSame(
            [['round' => 1, 'lines' => [['provider' => 'runway', 'credits' => '3.50'], ['provider' => 'Kling', 'credits' => '1.00']]]],
            $this->usageEvents($id),
        );
        $event = $this->fetchRows("SELECT actor_id, at FROM task_events WHERE type = 'ai_usage_changed'");
        self::assertSame([['actor_id' => $this->dave->id, 'at' => '2026-01-15 12:00:00.125000']], $event);

        // The buyer decides the round: the lines are frozen.
        self::assertSame(200, $this->approve($id)->getStatusCode());
        $late = $this->replace($id, 1, [['provider' => 'Kling', 'credits' => '99']]);
        self::assertSame(409, $late->getStatusCode(), (string) $late->getBody());
        $this->assertMatchesSpec($late);
        self::assertSame('invalid_transition', self::jsonAt($late, 'error.code'));
        self::assertSame(
            [['provider' => 'runway', 'credits' => '3.50'], ['provider' => 'Kling', 'credits' => '1.00']],
            $this->storedLines($id, 1),
        );
        self::assertCount(1, $this->usageEvents($id));
    }

    public function test_revisions_requested_freezes_the_round_too(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');
        self::assertSame(200, $this->requestRevisions($id)->getStatusCode());

        // The task is revisions_required: the designer reports usage for the next round on submit.
        $response = $this->replace($id, 1, [['provider' => 'Kling', 'credits' => '1']]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1));
        self::assertSame([], $this->usageEvents($id));

        // ...and a new round can carry its own lines, leaving round 1 alone.
        $next = $this->submitWithUsage($id, [['provider' => 'Runway', 'credits' => '2']]);
        self::assertSame(201, $next->getStatusCode(), (string) $next->getBody());
        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1));
        self::assertSame([['provider' => 'Runway', 'credits' => '2.00']], $this->storedLines($id, 2));
        $again = $this->replace($id, 2, [['provider' => 'Runway', 'credits' => '3']]);
        self::assertSame(200, $again->getStatusCode(), (string) $again->getBody());
    }

    public function test_only_submitting_designer_may_edit(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');
        $lines = [['provider' => 'Kling', 'credits' => '1']];

        // Eve cannot even see a task Dave holds: 404.
        $stranger = $this->replace($id, 1, $lines, $this->eve);
        self::assertSame(404, $stranger->getStatusCode());
        $this->assertMatchesSpec($stranger);
        self::assertSame('not_found', self::jsonAt($stranger, 'error.code'));

        // The buyer and the manager can see it but are not the designer.
        foreach ([$this->anna, $this->mia] as $viewer) {
            $response = $this->replace($id, 1, $lines, $viewer);
            self::assertSame(403, $response->getStatusCode(), (string) $response->getBody());
            $this->assertMatchesSpec($response);
            self::assertSame('forbidden', self::jsonAt($response, 'error.code'));
        }

        // The task is Eve's now, but round 1 was submitted by Dave: 403, whatever its decision.
        $eves = $this->insertTask($this->anna, status: TaskStatus::InProgress, assignee: $this->eve);
        $this->insertRound($eves, 1, decision: 'revisions_requested', reason: 'Again');
        $theirs = $this->replace($eves, 1, $lines, $this->eve);
        self::assertSame(403, $theirs->getStatusCode(), (string) $theirs->getBody());
        $this->assertMatchesSpec($theirs);
        self::assertSame('forbidden', self::jsonAt($theirs, 'error.code'));

        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1));
        self::assertSame(0, $this->countRows('task_events'));
    }

    public function test_a_designer_without_the_task_gets_403_on_a_new_task_and_404_for_unknown_things(): void
    {
        $pool = $this->insertTask($this->anna);
        $lines = [['provider' => 'Kling', 'credits' => '1']];

        $new = $this->replace($pool, 1, $lines, $this->eve);
        self::assertSame(403, $new->getStatusCode());
        $this->assertMatchesSpec($new);

        $id = $this->taskInStatus(TaskStatus::UnderReview);
        foreach ([[999999, 1], [$id, 2], [$id, 0]] as [$taskId, $number]) {
            $response = $this->replace($taskId, $number, $lines);
            self::assertSame(404, $response->getStatusCode(), $taskId . '/' . $number);
            $this->assertMatchesSpec($response);
            self::assertSame('not_found', self::jsonAt($response, 'error.code'));
        }
    }

    public function test_a_task_that_takes_no_usage_any_more_answers_409(): void
    {
        // Cancelled while the round was under review: the round stays undecided, but nothing may change.
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');
        self::assertSame(200, $this->cancel($id)->getStatusCode());

        $response = $this->replace($id, 1, [['provider' => 'Kling', 'credits' => '1']]);

        self::assertSame(409, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('invalid_transition', self::jsonAt($response, 'error.code'));
        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1));
    }

    public function test_an_identical_set_records_nothing(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');
        $this->usage($round, 'Runway', '2.5');
        $rowIds = $this->fetchRows('SELECT id FROM ai_usage ORDER BY id');
        $updatedAt = $this->fetchValue('SELECT updated_at FROM tasks WHERE id = ?', [$id]);

        // The same lines, spelled differently and in another order.
        $response = $this->replace($id, 1, [['provider' => ' RUNWAY', 'credits' => '2.50'], ['provider' => 'kling', 'credits' => '10']]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame([], $this->usageEvents($id));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame($rowIds, $this->fetchRows('SELECT id FROM ai_usage ORDER BY id'), 'the stored rows are left alone');
        self::assertSame($updatedAt, $this->fetchValue('SELECT updated_at FROM tasks WHERE id = ?', [$id]));
        self::assertSame(
            [['provider' => 'Kling', 'credits' => '10.00'], ['provider' => 'Runway', 'credits' => '2.50']],
            self::jsonAt($response, 'rounds.0.aiUsage'),
        );
    }

    public function test_replacing_with_nothing_clears_the_lines_and_says_so(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');

        $response = $this->replace($id, 1, []);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame([], self::jsonAt($response, 'rounds.0.aiUsage'));
        self::assertSame('0.00', self::jsonAt($response, 'creditsTotal'));
        self::assertSame([['round' => 1, 'lines' => []]], $this->usageEvents($id));
        self::assertSame(1, $this->countRows('ai_providers'), 'providers are never removed');

        // Clearing nothing again is not a change.
        self::assertSame(200, $this->replace($id, 1, [])->getStatusCode());
        self::assertCount(1, $this->usageEvents($id));
    }

    public function test_same_provider_in_several_lines_is_kept_as_separate_lines(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        $response = $this->replace($id, 1, [
            ['provider' => 'Kling', 'credits' => '0.10'],
            ['provider' => 'kling', 'credits' => '0.20'],
        ]);

        self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([['provider' => 'Kling', 'credits' => '0.10'], ['provider' => 'Kling', 'credits' => '0.20']], $this->storedLines($id, 1));
        self::assertSame('0.30', self::jsonAt($response, 'creditsTotal'));
    }

    public function test_bad_lines_are_422_with_field_names_under_lines(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');

        $cases = [
            'amount' => [[['provider' => 'Kling', 'credits' => '1.234']], [['field' => 'lines.0.credits', 'code' => 'invalid_amount', 'params' => []]]],
            'number' => [[['provider' => 'Kling', 'credits' => 5]], [['field' => 'lines.0.credits', 'code' => 'invalid', 'params' => []]]],
            'provider' => [[['credits' => '1']], [['field' => 'lines.0.provider', 'code' => 'required', 'params' => []]]],
            'too many' => [array_fill(0, 21, ['provider' => 'Kling', 'credits' => '1']), [['field' => 'lines', 'code' => 'too_many', 'params' => ['max' => 20]]]],
            'not a list' => ['Kling', [['field' => 'lines', 'code' => 'invalid', 'params' => []]]],
        ];
        foreach ($cases as $name => [$lines, $fields]) {
            $response = $this->replace($id, 1, $lines);

            self::assertSame(422, $response->getStatusCode(), $name . ': ' . $response->getBody());
            $this->assertMatchesSpec($response);
            self::assertSame($fields, self::jsonAt($response, 'error.fields'), $name);
        }

        // No body, or no `lines`, or null.
        foreach ([[], ['other' => 1], ['lines' => null]] as $body) {
            $response = $this->request('PUT', '/api/tasks/' . $id . '/rounds/1/ai-usage', $body, $this->dave);
            self::assertSame(422, $response->getStatusCode(), json_encode($body, JSON_THROW_ON_ERROR));
            $this->assertMatchesSpec($response);
            self::assertSame([['field' => 'lines', 'code' => 'required', 'params' => []]], self::jsonAt($response, 'error.fields'));
        }

        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1));
        self::assertSame([], $this->usageEvents($id));
    }

    public function test_permission_and_state_are_checked_before_the_body(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $bad = [['provider' => 'Kling', 'credits' => 'x']];

        self::assertSame(403, $this->replace($id, 1, $bad, $this->anna)->getStatusCode());
        self::assertSame(404, $this->replace($id, 1, $bad, $this->eve)->getStatusCode());
        self::assertSame(404, $this->replace($id, 5, $bad)->getStatusCode());
        self::assertSame(200, $this->approve($id)->getStatusCode());
        self::assertSame(409, $this->replace($id, 1, $bad)->getStatusCode());
    }

    public function test_csrf_and_login_are_required(): void
    {
        $id = $this->taskInStatus(TaskStatus::UnderReview);

        $anonymous = $this->handle($this->buildRequest('PUT', '/api/tasks/' . $id . '/rounds/1/ai-usage', ['lines' => []]));
        self::assertSame(401, $anonymous->getStatusCode());
        $this->assertMatchesSpec($anonymous);

        [$token] = $this->startSession($this->dave);
        $noCsrf = $this->handle(self::withSession($this->buildRequest('PUT', '/api/tasks/' . $id . '/rounds/1/ai-usage', ['lines' => []]), $token));
        self::assertSame(403, $noCsrf->getStatusCode());
        $this->assertMatchesSpec($noCsrf);
    }

    public function test_the_update_is_all_or_nothing(): void
    {
        $pdo = $this->database->pdo();
        $id = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($id);
        $this->usage($round, 'Kling', '10');
        $this->usage($round, 'Runway', '2.50');
        $providers = $this->countRows('ai_providers');
        $pdo->exec("CREATE TRIGGER fail_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

        try {
            $response = $this->replace($id, 1, [['provider' => 'Kling', 'credits' => '99'], ['provider' => 'Brand New', 'credits' => '1']]);
        } finally {
            $pdo->exec('DROP TRIGGER fail_events');
        }

        self::assertSame(500, $response->getStatusCode());
        self::assertSame([['provider' => 'Kling', 'credits' => '10.00'], ['provider' => 'Runway', 'credits' => '2.50']], $this->storedLines($id, 1));
        self::assertSame($providers, $this->countRows('ai_providers'), 'the provider created for the request is rolled back too');
        self::assertSame(0, $this->countRows('task_events'));
    }
}
