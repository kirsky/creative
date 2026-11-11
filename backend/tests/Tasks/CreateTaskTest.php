<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\Geo\GeoCodes;
use App\Tasks\TaskPolicy;
use App\Tests\Files\Samples;
use App\Tests\Support\ApiTestCase;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Slim\Psr7\Factory\StreamFactory;

/**
 * POST /api/tasks: a buyer writes a brief. The task, its geo, its references, the attached files and
 * the task_created event are stored together or not at all.
 */
final class CreateTaskTest extends ApiTestCase
{
    private User $buyer;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->createUser('anna', Role::Buyer);
    }

    public function test_buyer_creates_task_201_and_event_logged(): void
    {
        $response = $this->create($this->body());

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        $json = self::jsonArray($response);
        self::assertSame([
            'offer' => 'Лото Клуб',
            'geo' => ['DE', 'US'],
            'creativeType' => 'video',
            'quantity' => 3,
            'priority' => 'high',
            'deadlineAt' => '2026-01-20T10:00:00Z',
            'status' => 'new',
            'createdBy' => ['id' => $this->buyer->id, 'displayName' => 'Anna Test'],
            'assignee' => null,
            'roundCount' => 0,
            'creditsTotal' => '0.00',
            'isOverdue' => false,
            'isReturning' => false,
            'allowedActions' => ['edit_brief', 'cancel'],
            'description' => 'Make it pop',
            'references' => [],
            'assignments' => [],
            'rounds' => [],
            'metrics' => null,
        ], array_diff_key($json, ['id' => 0]));

        $rows = $this->fetchRows('SELECT * FROM tasks');
        self::assertCount(1, $rows);
        self::assertSame($json['id'], $rows[0]['id']);
        self::assertSame('new', $rows[0]['status']);
        self::assertNull($rows[0]['assignee_id']);
        self::assertSame('2026-01-20 10:00:00', $rows[0]['deadline_at']);
        self::assertSame('2026-01-15 09:00:00', $rows[0]['created_at']);
        self::assertSame('2026-01-15 09:00:00', $rows[0]['updated_at']);
        self::assertSame(['DE', 'US'], array_column($this->fetchRows('SELECT code FROM task_geo ORDER BY code'), 'code'));

        // One task_created event, with an empty payload, by the buyer.
        $events = $this->fetchRows('SELECT task_id, type, actor_id, payload FROM task_events');
        self::assertSame([['task_id' => $json['id'], 'type' => 'task_created', 'actor_id' => $this->buyer->id, 'payload' => '{}']], $events);
    }

    public function test_a_deadline_with_an_offset_is_stored_in_utc(): void
    {
        $response = $this->create($this->body(['deadline' => '2026-01-20T13:30:00+03:00']));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame('2026-01-20 10:30:00', $this->fetchValue('SELECT deadline_at FROM tasks'));
        self::assertSame('2026-01-20T10:30:00Z', self::jsonAt($response, 'deadlineAt'));
    }

    public function test_designer_and_manager_get_403(): void
    {
        foreach ([$this->createUser('dave', Role::Designer), $this->createUser('mia', Role::Manager)] as $user) {
            $response = $this->create($this->body(), $user);

            self::assertSame(403, $response->getStatusCode(), $user->username);
            self::assertSame('{"error":{"code":"forbidden","fields":[]}}', (string) $response->getBody());
            $this->assertMatchesSpec($response);
        }

        self::assertSame(0, $this->countRows('tasks'));
    }

    /**
     * @param array<string, mixed> $overrides
     * @param list<string> $remove
     * @param array{field: string, code: string, params: array<string, int>} $expected
     */
    #[DataProvider('invalidInputs')]
    public function test_validation_codes(array $overrides, array $remove, array $expected): void
    {
        $body = [...$this->body($overrides)];
        foreach ($remove as $key) {
            unset($body[$key]);
        }

        $response = $this->create($body);

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
        self::assertSame([$expected], self::jsonAt($response, 'error.fields'));
        self::assertSame(0, $this->countRows('tasks'));
        self::assertSame(0, $this->countRows('task_events'));
    }

    /**
     * @return iterable<string, array{array<string, mixed>, list<string>, array{field: string, code: string, params: array<string, int>}}>
     */
    public static function invalidInputs(): iterable
    {
        yield 'offer too short' => [['offer' => 'a'], [], ['field' => 'offer', 'code' => 'too_short', 'params' => ['min' => 2]]];
        yield 'offer too long' => [['offer' => str_repeat('я', 101)], [], ['field' => 'offer', 'code' => 'too_long', 'params' => ['max' => 100]]];
        yield 'offer blank' => [['offer' => '   '], [], ['field' => 'offer', 'code' => 'required', 'params' => []]];
        yield 'offer missing' => [[], ['offer'], ['field' => 'offer', 'code' => 'required', 'params' => []]];
        yield 'offer not a string' => [['offer' => 5], [], ['field' => 'offer', 'code' => 'invalid', 'params' => []]];
        yield 'quantity zero' => [['quantity' => 0], [], ['field' => 'quantity', 'code' => 'out_of_range', 'params' => ['min' => 1, 'max' => 100]]];
        yield 'quantity 101' => [['quantity' => 101], [], ['field' => 'quantity', 'code' => 'out_of_range', 'params' => ['min' => 1, 'max' => 100]]];
        yield 'quantity a string' => [['quantity' => '5'], [], ['field' => 'quantity', 'code' => 'invalid', 'params' => []]];
        yield 'quantity missing' => [[], ['quantity'], ['field' => 'quantity', 'code' => 'required', 'params' => []]];
        yield 'description missing' => [[], ['description'], ['field' => 'description', 'code' => 'required', 'params' => []]];
        yield 'description blank' => [['description' => " \n "], [], ['field' => 'description', 'code' => 'required', 'params' => []]];
        yield 'description too long' => [['description' => str_repeat('x', 10001)], [], ['field' => 'description', 'code' => 'too_long', 'params' => ['max' => 10000]]];
        yield 'unknown creative type' => [['creativeType' => 'hologram'], [], ['field' => 'creativeType', 'code' => 'invalid_choice', 'params' => []]];
        yield 'unknown priority' => [['priority' => 'asap'], [], ['field' => 'priority', 'code' => 'invalid_choice', 'params' => []]];
        yield 'priority missing' => [[], ['priority'], ['field' => 'priority', 'code' => 'required', 'params' => []]];
        yield 'deadline in the past' => [['deadline' => '2026-01-14T09:00:00Z'], [], ['field' => 'deadline', 'code' => 'must_be_future', 'params' => []]];
        yield 'deadline is now' => [['deadline' => '2026-01-15T09:00:00Z'], [], ['field' => 'deadline', 'code' => 'must_be_future', 'params' => []]];
        yield 'deadline in the past with an offset' => [['deadline' => '2026-01-15T11:00:00+03:00'], [], ['field' => 'deadline', 'code' => 'must_be_future', 'params' => []]];
        yield 'deadline missing' => [[], ['deadline'], ['field' => 'deadline', 'code' => 'required', 'params' => []]];
        yield 'deadline words' => [['deadline' => 'tomorrow'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline without a zone' => [['deadline' => '2026-01-20T10:00:00'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline that is no date' => [['deadline' => '2026-02-30T10:00:00Z'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline beyond year 9999 in UTC' => [['deadline' => '9999-12-31T23:59:59-01:00'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline in year 9999' => [['deadline' => '9999-12-31T23:59:59Z'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline a second beyond ten years' => [['deadline' => '2036-01-15T09:00:01Z'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline on a day that is not a leap day' => [['deadline' => '2027-02-29T10:00:00Z'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline with a lower case z' => [['deadline' => '2026-01-20T10:00:00z'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline with a lower case t' => [['deadline' => '2026-01-20t10:00:00Z'], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline with a trailing newline' => [['deadline' => "2026-01-20T10:00:00Z\n"], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'deadline not a string' => [['deadline' => 1_800_000_000], [], ['field' => 'deadline', 'code' => 'invalid', 'params' => []]];
        yield 'geo empty' => [['geo' => []], [], ['field' => 'geo', 'code' => 'required', 'params' => []]];
        yield 'geo missing' => [[], ['geo'], ['field' => 'geo', 'code' => 'required', 'params' => []]];
        yield 'geo unknown code' => [['geo' => ['US', 'XX']], [], ['field' => 'geo', 'code' => 'invalid_choice', 'params' => []]];
        yield 'geo lower case' => [['geo' => ['us']], [], ['field' => 'geo', 'code' => 'invalid_choice', 'params' => []]];
        yield 'geo with more than 30 codes' => [['geo' => array_slice(GeoCodes::countries(), 0, 31)], [], ['field' => 'geo', 'code' => 'too_long', 'params' => ['max' => 30]]];
        yield 'geo not a list' => [['geo' => 'US'], [], ['field' => 'geo', 'code' => 'invalid', 'params' => []]];
        yield 'more than 50 references' => [['references' => array_fill(0, 51, ['type' => 'url', 'url' => 'https://example.com'])], [], ['field' => 'references', 'code' => 'too_long', 'params' => ['max' => 50]]];
        yield 'javascript url' => [['references' => [['type' => 'url', 'url' => 'javascript:alert(1)']]], [], ['field' => 'references.0.url', 'code' => 'invalid_url', 'params' => []]];
        yield 'ftp url' => [['references' => [['type' => 'url', 'url' => 'ftp://example.com/a']]], [], ['field' => 'references.0.url', 'code' => 'invalid_url', 'params' => []]];
        yield 'url without a host' => [['references' => [['type' => 'url', 'url' => 'https://']]], [], ['field' => 'references.0.url', 'code' => 'invalid_url', 'params' => []]];
        yield 'url with a space' => [['references' => [['type' => 'url', 'url' => 'https://exa mple.com']]], [], ['field' => 'references.0.url', 'code' => 'invalid_url', 'params' => []]];
        yield 'url too long' => [['references' => [['type' => 'url', 'url' => 'https://example.com/' . str_repeat('a', 1981)]]], [], ['field' => 'references.0.url', 'code' => 'too_long', 'params' => ['max' => 2000]]];
        yield 'url missing' => [['references' => [['type' => 'url']]], [], ['field' => 'references.0.url', 'code' => 'required', 'params' => []]];
        yield 'reference of an unknown type' => [['references' => [['type' => 'ftp', 'url' => 'https://a.example']]], [], ['field' => 'references.0.type', 'code' => 'invalid_choice', 'params' => []]];
        yield 'reference file id is not a number' => [['references' => [['type' => 'file', 'fileId' => 'abc']]], [], ['field' => 'references.0.fileId', 'code' => 'invalid', 'params' => []]];
        yield 'references not a list' => [['references' => 'https://example.com'], [], ['field' => 'references', 'code' => 'invalid', 'params' => []]];
        yield 'unknown field' => [['owner' => 7], [], ['field' => 'owner', 'code' => 'unknown_field', 'params' => []]];
    }

    /**
     * @return iterable<string, array{string, string}> the deadline sent and the one stored (UTC)
     */
    public static function acceptedDeadlines(): iterable
    {
        yield 'a leap day' => ['2028-02-29T10:00:00Z', '2028-02-29 10:00:00'];
        yield 'fractional seconds are cut off' => ['2026-01-20T10:00:00.987654Z', '2026-01-20 10:00:00'];
        yield 'exactly ten years from now' => ['2036-01-15T09:00:00Z', '2036-01-15 09:00:00'];
        yield 'an offset' => ['2026-01-20T10:00-05:00', '2026-01-20 15:00:00'];
    }

    #[DataProvider('acceptedDeadlines')]
    public function test_accepted_deadlines(string $sent, string $stored): void
    {
        $response = $this->create($this->body(['deadline' => $sent]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        self::assertSame($stored, $this->fetchValue('SELECT deadline_at FROM tasks'));
    }

    /**
     * @param non-empty-string $json
     */
    #[DataProvider('bodiesThatAreNoObject')]
    public function test_a_body_that_is_no_json_object_is_422(string $json): void
    {
        [$token, $csrfToken] = $this->startSession($this->buyer);
        $request = self::withSession($this->buildRequest('POST', '/api/tasks'), $token, $csrfToken)
            ->withHeader('Content-Type', 'application/json')
            ->withBody((new StreamFactory())->createStream($json));

        $response = $this->handle($request);

        self::assertSame(422, $response->getStatusCode(), $json);
        $this->assertMatchesSpec($response);
        self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
        self::assertSame(0, $this->countRows('tasks'));
    }

    /**
     * @return iterable<string, array{non-empty-string}>
     */
    public static function bodiesThatAreNoObject(): iterable
    {
        yield 'a string' => ['"Лото"'];
        yield 'a number' => ['42'];
        yield 'null' => ['null'];
        yield 'true' => ['true'];
        yield 'a list' => ['[1,2]'];
    }

    public function test_the_same_file_twice_in_one_request_is_422(): void
    {
        $fileId = $this->uploadFile($this->buyer, 'brief.png');

        $response = $this->create($this->body(['references' => [
            ['type' => 'file', 'fileId' => $fileId],
            ['type' => 'file', 'fileId' => $fileId],
        ]]));

        self::assertSame(422, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
        foreach (['tasks', 'task_geo', 'task_references', 'file_attachments', 'task_events'] as $table) {
            self::assertSame(0, $this->countRows($table), $table);
        }
    }

    public function test_every_problem_is_reported_at_once(): void
    {
        $response = $this->create(['offer' => 'a', 'quantity' => 0]);

        $fields = array_column(self::jsonArray($response, 'error.fields'), 'code', 'field');
        self::assertEquals(
            ['offer' => 'too_short', 'quantity' => 'out_of_range', 'geo' => 'required', 'creativeType' => 'required', 'description' => 'required', 'priority' => 'required', 'deadline' => 'required'],
            $fields,
        );
    }

    public function test_emoji_cyrillic_and_html_description_roundtrip_byte_identical(): void
    {
        $text = '🎰 <script>alert(1)</script> Лото';
        $offer = '🎰 Лото <b>Клуб</b>';

        $response = $this->create($this->body(['description' => $text, 'offer' => $offer]));

        self::assertSame(201, $response->getStatusCode());
        $id = self::jsonAt($response, 'id');
        self::assertSame($text, self::jsonAt($response, 'description'));
        self::assertSame($text, $this->fetchValue('SELECT description FROM tasks'));
        self::assertSame($offer, $this->fetchValue('SELECT offer FROM tasks'));
        // The raw bytes on the wire hold no markup: "<" is escaped inside the JSON string.
        self::assertStringNotContainsString('<script>', (string) $response->getBody());

        $read = $this->request('GET', '/api/tasks/' . (is_int($id) ? $id : 0), as: $this->buyer);
        self::assertSame($text, self::jsonAt($read, 'description'));
        self::assertSame($offer, self::jsonAt($read, 'offer'));
    }

    public function test_surrounding_whitespace_of_the_offer_is_trimmed_but_the_description_is_kept(): void
    {
        $this->create($this->body(['offer' => "  Лото Клуб \n", 'description' => "  line 1\nline 2\n"]));

        self::assertSame('Лото Клуб', $this->fetchValue('SELECT offer FROM tasks'));
        self::assertSame("  line 1\nline 2\n", $this->fetchValue('SELECT description FROM tasks'));
    }

    public function test_a_repeated_geo_code_is_stored_once(): void
    {
        $response = $this->create($this->body(['geo' => ['WW', 'US', 'WW']]));

        self::assertSame(201, $response->getStatusCode());
        self::assertSame(['US', 'WW'], self::jsonAt($response, 'geo'));
    }

    public function test_url_references_are_stored_and_returned_in_order(): void
    {
        $response = $this->create($this->body(['references' => [
            ['type' => 'url', 'url' => 'https://example.com/a?b=1#c'],
            ['type' => 'url', 'url' => 'http://пример.рф/страница'],
        ]]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(
            [['type' => 'url', 'url' => 'https://example.com/a?b=1#c'], ['type' => 'url', 'url' => 'http://пример.рф/страница']],
            self::jsonAt($response, 'references'),
        );
        self::assertSame(
            [['kind' => 'url', 'file_id' => null], ['kind' => 'url', 'file_id' => null]],
            $this->fetchRows('SELECT kind, file_id FROM task_references ORDER BY id'),
        );
    }

    public function test_a_reference_file_is_attached_to_the_task(): void
    {
        $fileId = $this->uploadFile($this->buyer, 'brief.png');

        $response = $this->create($this->body(['references' => [['type' => 'file', 'fileId' => $fileId]]]));

        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $this->assertMatchesSpec($response);
        self::assertSame(
            [['type' => 'file', 'fileId' => $fileId, 'name' => 'brief.png', 'mime' => 'image/png', 'size' => strlen(Samples::png())]],
            self::jsonAt($response, 'references'),
        );
        self::assertSame(
            [['file_id' => $fileId, 'task_id' => self::jsonAt($response, 'id'), 'kind' => 'reference']],
            $this->fetchRows('SELECT file_id, task_id, kind FROM file_attachments'),
        );
        self::assertSame(
            [['kind' => 'file', 'url' => null, 'file_id' => $fileId]],
            $this->fetchRows('SELECT kind, url, file_id FROM task_references'),
        );
    }

    public function test_reference_file_must_belong_to_actor(): void
    {
        $designersFile = $this->uploadFile($this->createUser('dave', Role::Designer), 'theirs.png');
        $otherBuyersFile = $this->uploadFile($this->createUser('otto', Role::Buyer), 'otto.png');

        foreach ([$designersFile, $otherBuyersFile, 987_654] as $fileId) {
            $response = $this->create($this->body(['references' => [['type' => 'file', 'fileId' => $fileId]]]));

            self::assertSame(422, $response->getStatusCode(), (string) $fileId);
            $this->assertMatchesSpec($response);
            self::assertSame('validation_failed', self::jsonAt($response, 'error.code'));
            self::assertSame('invalid_choice', self::jsonAt($response, 'error.fields.0.code'));
        }

        self::assertSame(0, $this->countRows('tasks'));
        self::assertSame(0, $this->countRows('file_attachments'));
    }

    public function test_a_file_that_is_attached_already_cannot_be_a_reference_again(): void
    {
        $fileId = $this->uploadFile($this->buyer, 'brief.png');
        $first = $this->create($this->body(['references' => [['type' => 'file', 'fileId' => $fileId]]]));
        self::assertSame(201, $first->getStatusCode());

        $second = $this->create($this->body(['references' => [['type' => 'file', 'fileId' => $fileId]]]));

        self::assertSame(422, $second->getStatusCode());
        self::assertSame(1, $this->countRows('tasks'));
    }

    public function test_a_failure_part_way_leaves_no_task_event_geo_reference_or_attachment(): void
    {
        $mine = $this->uploadFile($this->buyer, 'mine.png');
        $notMine = $this->uploadFile($this->createUser('dave', Role::Designer), 'theirs.png');

        $response = $this->create($this->body(['references' => [
            ['type' => 'url', 'url' => 'https://example.com'],
            ['type' => 'file', 'fileId' => $mine],
            ['type' => 'file', 'fileId' => $notMine],
        ]]));

        self::assertSame(422, $response->getStatusCode());
        foreach (['tasks', 'task_geo', 'task_references', 'file_attachments', 'task_events'] as $table) {
            self::assertSame(0, $this->countRows($table), $table);
        }
    }

    public function test_a_failure_of_the_event_insert_rolls_everything_back(): void
    {
        $mine = $this->uploadFile($this->buyer, 'mine.png');
        $pdo = $this->database->pdo();
        $pdo->exec("CREATE TRIGGER fail_task_events BEFORE INSERT ON task_events FOR EACH ROW SIGNAL SQLSTATE '45000' SET MESSAGE_TEXT = 'boom'");

        try {
            $response = $this->create($this->body(['references' => [['type' => 'file', 'fileId' => $mine]]]));
        } finally {
            $pdo->exec('DROP TRIGGER fail_task_events');
        }

        self::assertSame(500, $response->getStatusCode());
        foreach (['tasks', 'task_geo', 'task_references', 'file_attachments', 'task_events'] as $table) {
            self::assertSame(0, $this->countRows($table), $table);
        }
        self::assertSame(1, $this->countRows('files'));
    }

    public function test_the_policy_is_wired_by_the_container(): void
    {
        // TaskPolicy has an optional TaskWorkflow argument; the container must be able to build it.
        self::assertInstanceOf(TaskPolicy::class, $this->service(TaskPolicy::class));
    }

    /**
     * The request every case starts from; $overrides replace keys.
     *
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function body(array $overrides = []): array
    {
        return [
            'offer' => 'Лото Клуб',
            'geo' => ['US', 'DE'],
            'creativeType' => 'video',
            'quantity' => 3,
            'description' => 'Make it pop',
            'priority' => 'high',
            'deadline' => '2026-01-20T10:00:00Z',
            ...$overrides,
        ];
    }

    /**
     * @param array<string, mixed> $body
     */
    private function create(array $body, ?User $as = null): ResponseInterface
    {
        return $this->request('POST', '/api/tasks', $body, $as ?? $this->buyer);
    }

    private function uploadFile(User $uploader, string $name): int
    {
        $response = $this->upload(Samples::png(), $name, as: $uploader);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());
        $id = self::jsonAt($response, 'id');

        return is_int($id) ? $id : self::fail('The upload answered no id.');
    }
}
