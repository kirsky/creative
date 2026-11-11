<?php

declare(strict_types=1);

namespace App\Tests\AiUsage;

use App\Tasks\RoundReader;
use App\Tasks\TaskStatus;
use PHPUnit\Framework\Attributes\DataProvider;

/**
 * AI usage on submit: the lines of `aiUsage` are checked together with the files, stored for the new
 * round, recorded as an event right after round_submitted, and shown on the task page.
 */
final class AiUsageTest extends AiUsageTestCase
{
    public function test_lines_stored_per_round_and_returned_in_detail(): void
    {
        $id = $this->taskOfDave();

        $response = $this->submitWithUsage($id, [
            ['provider' => 'Midjourney', 'credits' => '40'],
            ['provider' => 'Runway', 'credits' => '12.5'],
            ['provider' => 'Midjourney', 'credits' => '0.05'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(
            [
                ['provider' => 'Midjourney', 'credits' => '40.00'],
                ['provider' => 'Runway', 'credits' => '12.50'],
                ['provider' => 'Midjourney', 'credits' => '0.05'],
            ],
            $this->storedLines($id, 1),
        );

        foreach ([$this->dave, $this->anna, $this->mia] as $viewer) {
            $detail = $this->request('GET', '/api/tasks/' . $id, as: $viewer);
            $this->assertMatchesSpec($detail);
            self::assertSame(
                [
                    ['provider' => 'Midjourney', 'credits' => '40.00'],
                    ['provider' => 'Runway', 'credits' => '12.50'],
                    ['provider' => 'Midjourney', 'credits' => '0.05'],
                ],
                self::jsonAt($detail, 'rounds.0.aiUsage'),
            );
            self::assertSame('52.55', self::jsonAt($detail, 'creditsTotal'));
        }
    }

    public function test_each_round_keeps_its_own_lines(): void
    {
        $id = $this->taskOfDave();
        $first = $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => '10']]);
        self::assertSame(201, $first->getStatusCode());
        $this->request('POST', '/api/tasks/' . $id . '/request-revisions', ['reason' => 'Again'], $this->anna);
        $second = $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => '2.25'], ['provider' => 'OpenAI', 'credits' => '1']]);
        self::assertSame(201, $second->getStatusCode(), (string) $second->getBody());

        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], $this->storedLines($id, 1));
        self::assertSame([['provider' => 'Kling', 'credits' => '2.25'], ['provider' => 'OpenAI', 'credits' => '1.00']], $this->storedLines($id, 2));

        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        self::assertSame([['provider' => 'Kling', 'credits' => '10.00']], self::jsonAt($detail, 'rounds.0.aiUsage'));
        self::assertSame(
            [['provider' => 'Kling', 'credits' => '2.25'], ['provider' => 'OpenAI', 'credits' => '1.00']],
            self::jsonAt($detail, 'rounds.1.aiUsage'),
        );
    }

    public function test_provider_name_case_insensitive_reuse(): void
    {
        $id = $this->taskOfDave();

        $response = $this->submitWithUsage($id, [
            ['provider' => 'runway', 'credits' => '1'],
            ['provider' => ' Runway  ', 'credits' => '2'],
            ['provider' => "RUNWAY\t", 'credits' => '3'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        // The first spelling to arrive is the one every later one maps to.
        self::assertSame(['runway'], array_column($this->fetchRows('SELECT name FROM ai_providers'), 'name'));
        self::assertSame(3, $this->countRows('ai_usage'));
        self::assertSame(1, $this->fetchValue('SELECT COUNT(DISTINCT provider_id) FROM ai_usage'));
    }

    public function test_existing_providers_keep_their_spelling_and_new_ones_are_created_once(): void
    {
        $this->provider('Adobe Firefly');
        $id = $this->taskOfDave();

        $response = $this->submitWithUsage($id, [
            ['provider' => '  adobe    FIREFLY ', 'credits' => '5'],
            ['provider' => "Pika\n  Labs", 'credits' => '6'],
            ['provider' => 'pika labs', 'credits' => '7'],
        ]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['Adobe Firefly', 'Pika Labs'], array_column($this->fetchRows('SELECT name FROM ai_providers ORDER BY id'), 'name'));
        self::assertSame(
            [
                ['provider' => 'Adobe Firefly', 'credits' => '5.00'],
                ['provider' => 'Pika Labs', 'credits' => '6.00'],
                ['provider' => 'Pika Labs', 'credits' => '7.00'],
            ],
            $this->storedLines($id, 1),
        );
        // The event names the stored spelling, not what the designer typed.
        self::assertSame(
            [['round' => 1, 'lines' => [
                ['provider' => 'Adobe Firefly', 'credits' => '5.00'],
                ['provider' => 'Pika Labs', 'credits' => '6.00'],
                ['provider' => 'Pika Labs', 'credits' => '7.00'],
            ]]],
            $this->usageEvents($id),
        );
    }

    public function test_provider_names_are_counted_in_characters(): void
    {
        $id = $this->taskOfDave();
        $fifty = str_repeat('я', 50);

        $ok = $this->submitWithUsage($id, [['provider' => $fifty, 'credits' => '1']]);
        self::assertSame(201, $ok->getStatusCode(), (string) $ok->getBody());
        self::assertSame([$fifty], array_column($this->fetchRows('SELECT name FROM ai_providers'), 'name'));

        $other = $this->taskOfDave();
        $tooLong = $this->submitWithUsage($other, [['provider' => $fifty . 'я', 'credits' => '1']]);
        self::assertSame(422, $tooLong->getStatusCode());
        $this->assertMatchesSpec($tooLong);
        self::assertSame([['field' => 'aiUsage.0.provider', 'code' => 'too_long', 'params' => ['max' => 50]]], self::jsonAt($tooLong, 'error.fields'));
    }

    public function test_hostile_provider_names_round_trip_as_text(): void
    {
        $id = $this->taskOfDave();
        $name = '🎰 <script>alert(1)</script> Лото';

        $response = $this->submitWithUsage($id, [['provider' => $name, 'credits' => '3']]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        self::assertSame($name, self::jsonAt($detail, 'rounds.0.aiUsage.0.provider'));
    }

    public function test_zero_lines_is_valid(): void
    {
        foreach (['empty list', 'omitted'] as $case) {
            $id = $this->taskOfDave();
            $response = $case === 'omitted'
                ? $this->submit($id, [$this->uploadFor($this->dave)])
                : $this->submitWithUsage($id, []);

            self::assertSame(201, $response->getStatusCode(), $case . ': ' . $response->getBody());
            self::assertSame([], $this->storedLines($id, 1));
            self::assertSame(['round_submitted'], $this->eventTypes($id));
            $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
            self::assertSame([], self::jsonAt($detail, 'rounds.0.aiUsage'));
            self::assertSame('0.00', self::jsonAt($detail, 'creditsTotal'));
        }

        self::assertSame(0, $this->countRows('ai_usage'));
        self::assertSame(0, $this->countRows('ai_providers'));
    }

    public function test_submit_with_lines_records_both_events_in_order(): void
    {
        $id = $this->taskOfDave();

        $response = $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => '7.5']]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame(['round_submitted', 'ai_usage_changed'], $this->eventTypes($id));
        self::assertSame([['round' => 1, 'lines' => [['provider' => 'Kling', 'credits' => '7.50']]]], $this->usageEvents($id));
        $ids = array_column($this->fetchRows('SELECT id FROM task_events WHERE task_id = ? ORDER BY id', [$id]), 'id');
        self::assertCount(2, $ids);

        $history = $this->request('GET', '/api/tasks/' . $id . '/history', as: $this->anna);
        $this->assertMatchesSpec($history);
        self::assertSame(['round_submitted', 'ai_usage_changed'], array_column(self::jsonArray($history, 'events'), 'type'));
    }

    /**
     * @return iterable<string, array{mixed, string}>
     */
    public static function invalidAmounts(): iterable
    {
        yield 'negative' => ['-1', 'invalid_amount'];
        yield 'three decimals' => ['1.234', 'invalid_amount'];
        yield 'letters' => ['abc', 'invalid_amount'];
        yield 'exponent' => ['1e3', 'invalid_amount'];
        yield 'plus sign' => ['+5', 'invalid_amount'];
        yield 'leading space' => [' 5', 'invalid_amount'];
        yield 'trailing space' => ['5 ', 'invalid_amount'];
        yield 'trailing newline' => ["5\n", 'invalid_amount'];
        yield 'too big' => ['10000000', 'invalid_amount'];
        yield 'too big with decimals' => ['10000000.00', 'invalid_amount'];
        yield 'empty' => ['', 'invalid_amount'];
        yield 'bare dot' => ['.', 'invalid_amount'];
        yield 'leading dot' => ['.5', 'invalid_amount'];
        yield 'trailing dot' => ['5.', 'invalid_amount'];
        yield 'comma' => ['5,5', 'invalid_amount'];
        yield 'arabic digits' => ['٥', 'invalid_amount'];
        yield 'json integer' => [5, 'invalid'];
        yield 'json float' => [5.5, 'invalid'];
        yield 'json bool' => [true, 'invalid'];
        yield 'json null' => [null, 'invalid'];
        yield 'json list' => [['5'], 'invalid'];
    }

    #[DataProvider('invalidAmounts')]
    public function test_invalid_amounts(mixed $credits, string $code): void
    {
        $id = $this->taskOfDave();

        $response = $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => $credits]]);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
        self::assertSame([['field' => 'aiUsage.0.credits', 'code' => $code, 'params' => []]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingRecorded($id);
    }

    /**
     * @return iterable<string, array{string, string}>
     */
    public static function validAmounts(): iterable
    {
        yield 'zero' => ['0', '0.00'];
        yield 'zero with decimals' => ['0.00', '0.00'];
        yield 'one decimal' => ['0.5', '0.50'];
        yield 'two decimals' => ['12.34', '12.34'];
        yield 'leading zeros' => ['007', '7.00'];
        yield 'seven digits' => ['9999999', '9999999.00'];
        yield 'maximum' => ['9999999.99', '9999999.99'];
    }

    #[DataProvider('validAmounts')]
    public function test_valid_amounts_are_stored_exactly(string $sent, string $stored): void
    {
        $id = $this->taskOfDave();

        $response = $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => $sent]]);

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame([['provider' => 'Kling', 'credits' => $stored]], $this->storedLines($id, 1));
        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        self::assertSame($stored, self::jsonAt($detail, 'creditsTotal'));
    }

    public function test_at_most_20_lines(): void
    {
        $line = ['provider' => 'Kling', 'credits' => '1'];

        $ok = $this->taskOfDave();
        $response = $this->submitWithUsage($ok, array_fill(0, 20, $line));
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertCount(20, $this->storedLines($ok, 1));
        self::assertSame(1, $this->countRows('ai_providers'));

        $bad = $this->taskOfDave();
        $response = $this->submitWithUsage($bad, array_fill(0, 21, $line));
        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame([['field' => 'aiUsage', 'code' => 'too_many', 'params' => ['max' => 20]]], self::jsonAt($response, 'error.fields'));
        $this->assertNothingRecorded($bad);
    }

    public function test_shape_errors_name_the_field(): void
    {
        $id = $this->taskOfDave();

        $cases = [
            'not a list' => [['provider' => 'Kling', 'credits' => '1'], [['field' => 'aiUsage', 'code' => 'invalid', 'params' => []]]],
            'a string' => ['Kling', [['field' => 'aiUsage', 'code' => 'invalid', 'params' => []]]],
            'a line that is not an object' => [['Kling'], [['field' => 'aiUsage.0', 'code' => 'invalid', 'params' => []]]],
            'missing provider' => [[['credits' => '1']], [['field' => 'aiUsage.0.provider', 'code' => 'required', 'params' => []]]],
            'missing credits' => [[['provider' => 'Kling']], [['field' => 'aiUsage.0.credits', 'code' => 'required', 'params' => []]]],
            'blank provider' => [[['provider' => " \t ", 'credits' => '1']], [['field' => 'aiUsage.0.provider', 'code' => 'required', 'params' => []]]],
            'provider not a string' => [[['provider' => 7, 'credits' => '1']], [['field' => 'aiUsage.0.provider', 'code' => 'invalid', 'params' => []]]],
            'provider with a control character' => [[['provider' => "Kl\x00ing", 'credits' => '1']], [['field' => 'aiUsage.0.provider', 'code' => 'invalid', 'params' => []]]],
            'unknown key' => [[['provider' => 'Kling', 'credits' => '1', 'note' => 'x']], [['field' => 'aiUsage.0.note', 'code' => 'unknown_field', 'params' => []]]],
        ];

        foreach ($cases as $name => [$lines, $fields]) {
            $response = $this->submitWithUsage($id, $lines);

            self::assertSame(422, $response->getStatusCode(), $name . ': ' . $response->getBody());
            $this->assertMatchesSpec($response);
            self::assertSame($fields, self::jsonAt($response, 'error.fields'), $name);
        }

        $this->assertNothingRecorded($id);
    }

    public function test_every_bad_line_is_reported_and_files_errors_come_with_them(): void
    {
        $id = $this->taskOfDave();

        $response = $this->submit($id, [], extra: ['aiUsage' => [
            ['provider' => 'Kling', 'credits' => '-1'],
            ['provider' => 'Kling', 'credits' => '1'],
            ['provider' => '', 'credits' => 'x'],
        ]]);

        self::assertSame(422, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertEqualsCanonicalizing(
            [
                ['field' => 'fileIds', 'code' => 'required', 'params' => []],
                ['field' => 'aiUsage.0.credits', 'code' => 'invalid_amount', 'params' => []],
                ['field' => 'aiUsage.2.provider', 'code' => 'required', 'params' => []],
                ['field' => 'aiUsage.2.credits', 'code' => 'invalid_amount', 'params' => []],
            ],
            self::jsonAt($response, 'error.fields'),
        );
    }

    public function test_bad_usage_rejects_the_whole_round(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);

        $response = $this->submit($id, [$file], extra: ['aiUsage' => [['provider' => 'Kling', 'credits' => '1'], ['provider' => 'Runway', 'credits' => '1.234']]]);

        self::assertSame(422, $response->getStatusCode());
        $this->assertNothingRecorded($id);
        self::assertSame(0, $this->countRows('ai_providers'), 'a provider is not created for a request that is refused');
        self::assertSame(0, $this->fetchValue('SELECT COUNT(*) FROM file_attachments'), 'the file stays unattached');

        // The same file can still be handed in once the lines are right.
        $retry = $this->submit($id, [$file], extra: ['aiUsage' => [['provider' => 'Kling', 'credits' => '1.23']]]);
        self::assertSame(201, $retry->getStatusCode(), (string) $retry->getBody());
    }

    public function test_a_failure_after_the_lines_are_stored_rolls_everything_back(): void
    {
        $pdo = $this->database->pdo();
        $id = $this->taskOfDave();
        $pdo->exec("CREATE TRIGGER fail_usage_events BEFORE INSERT ON task_events FOR EACH ROW
            BEGIN IF NEW.type = 'ai_usage_changed' THEN SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'; END IF; END");

        try {
            $response = $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => '9']]);
        } finally {
            $pdo->exec('DROP TRIGGER fail_usage_events');
        }

        self::assertSame(500, $response->getStatusCode());
        $this->assertNothingRecorded($id);
        self::assertSame(0, $this->countRows('ai_providers'));
        self::assertSame(0, $this->countRows('task_events'));
        self::assertSame('in_progress', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$id]));
    }

    public function test_credits_total_adds_up_over_all_rounds_on_card_and_detail(): void
    {
        $id = $this->taskOfDave();
        $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => '0.10'], ['provider' => 'Runway', 'credits' => '40']]);
        $this->request('POST', '/api/tasks/' . $id . '/request-revisions', ['reason' => 'Again'], $this->anna);
        $this->submitWithUsage($id, [['provider' => 'Kling', 'credits' => '0.20']]);
        $empty = $this->taskOfDave();
        $this->submitWithUsage($empty, []);
        $other = $this->taskOfDave();
        $this->submitWithUsage($other, [['provider' => 'OpenAI', 'credits' => '5']]);

        $detail = $this->request('GET', '/api/tasks/' . $id, as: $this->anna);
        $this->assertMatchesSpec($detail);
        self::assertSame('40.30', self::jsonAt($detail, 'creditsTotal'));

        $board = $this->request('GET', '/api/tasks', as: $this->mia);
        $this->assertMatchesSpec($board);
        $totals = [];
        foreach (self::jsonArray($board, 'tasks') as $task) {
            self::assertIsArray($task);
            $totals[is_int($task['id'] ?? null) ? $task['id'] : 0] = $task['creditsTotal'] ?? null;
        }
        self::assertSame([$id => '40.30', $empty => '0.00', $other => '5.00'], $totals);
    }

    public function test_summaries_carry_the_credits_of_many_tasks(): void
    {
        $ids = [];
        for ($i = 0; $i < 4; ++$i) {
            $ids[] = $id = $this->taskOfDave(TaskStatus::UnderReview);
            $round = $this->insertRound($id, 1);
            $this->usage($round, 'Kling', '1.50');
            $this->usage($round, 'Runway', '2.25');
        }

        $summaries = $this->service(RoundReader::class)->summaries($ids);

        foreach ($ids as $id) {
            self::assertSame('3.75', $summaries[$id]->creditsTotal ?? null);
        }
    }

    private function assertNothingRecorded(int $taskId): void
    {
        self::assertSame(0, $this->fetchValue('SELECT COUNT(*) FROM rounds WHERE task_id = ?', [$taskId]));
        self::assertSame(0, $this->fetchValue('SELECT COUNT(*) FROM ai_usage u JOIN rounds r ON r.id = u.round_id WHERE r.task_id = ?', [$taskId]));
        self::assertSame([], $this->usageEvents($taskId));
        self::assertSame('in_progress', $this->fetchValue('SELECT status FROM tasks WHERE id = ?', [$taskId]));
    }
}
