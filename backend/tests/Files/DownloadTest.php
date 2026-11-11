<?php

declare(strict_types=1);

namespace App\Tests\Files;

use App\Files\AttachedTaskResolver;
use App\Files\AttachmentKind;
use App\Files\FileStore;
use App\Identity\Role;
use App\Identity\User;
use App\Tasks\Task;
use App\Tasks\TaskStatus;
use App\Tests\Support\ApiTestCase;
use DateTimeImmutable;
use PHPUnit\Framework\Attributes\DataProvider;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;

/**
 * GET /api/files/{id}: who may read a file, and exactly what the answer looks like. Whatever the
 * reason a file cannot be read (it does not exist, it is somebody else's, its bytes are gone), the
 * answer is the same 404, so a stranger learns nothing about which ids exist.
 */
final class DownloadTest extends ApiTestCase
{
    private User $buyer;

    private User $designer;

    private User $manager;

    private FakeAttachedTaskResolver $attachments;

    protected function setUp(): void
    {
        parent::setUp();

        $this->buyer = $this->createUser('anna', Role::Buyer);
        $this->designer = $this->createUser('dave', Role::Designer);
        $this->manager = $this->createUser('mia', Role::Manager);

        $this->attachments = new FakeAttachedTaskResolver();
        $this->bootApp($this->config, [AttachedTaskResolver::class => $this->attachments]);
    }

    public function test_unattached_file_readable_only_by_uploader(): void
    {
        $png = Samples::png();
        $id = $this->uploadAs($this->buyer, $png, 'brief.png');

        $mine = $this->download($id, $this->buyer);

        self::assertSame(200, $mine->getStatusCode());
        self::assertSame($png, (string) $mine->getBody());
        $this->assertMatchesSpec($mine);

        $strangers = [
            $this->createUser('otto', Role::Buyer),
            $this->designer,
            $this->manager,
        ];
        foreach ($strangers as $stranger) {
            $response = $this->download($id, $stranger);

            self::assertSame(404, $response->getStatusCode(), $stranger->username);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
            $this->assertMatchesSpec($response);
        }
    }

    public function test_a_file_that_does_not_exist_gets_the_same_answer_as_one_that_may_not_be_read(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), 'brief.png');

        $unreadable = $this->download($id, $this->designer);
        $missing = $this->download($id + 1000, $this->designer);

        self::assertSame(404, $missing->getStatusCode());
        self::assertSame((string) $unreadable->getBody(), (string) $missing->getBody());
        self::assertSame($unreadable->getHeaders(), $missing->getHeaders());
    }

    public function test_unauthenticated_download_is_401(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), 'brief.png');

        $response = $this->request('GET', '/api/files/' . $id);

        self::assertSame(401, $response->getStatusCode());
        self::assertSame('{"error":{"code":"unauthenticated","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
    }

    public function test_content_disposition_uses_rfc5987_for_cyrillic_name(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), 'мой файл (1).png');

        $response = $this->download($id, $this->buyer);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame(
            'attachment; filename="_ _ (1).png"; filename*=UTF-8\'\'%D0%BC%D0%BE%D0%B9%20%D1%84%D0%B0%D0%B9%D0%BB%20%281%29.png',
            $response->getHeaderLine('Content-Disposition'),
        );
        $this->assertMatchesSpec($response);
    }

    #[DataProvider('hostileNames')]
    public function test_content_disposition_is_header_safe_for_any_stored_name(string $name): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), $name);

        $response = $this->download($id, $this->buyer);

        self::assertSame(200, $response->getStatusCode());
        $header = $response->getHeaderLine('Content-Disposition');
        // One header line, a quoted ASCII fallback that cannot end the quote early, and an encoded UTF-8 name.
        self::assertMatchesRegularExpression('#^attachment; filename="[A-Za-z0-9 ._()+,=@\[\]_-]*"; filename\*=UTF-8\'\'[A-Za-z0-9%._~-]+$#', $header);
        self::assertCount(1, $response->getHeader('Content-Disposition'));

        // The encoded name decodes to the name that was stored.
        $stored = $this->fetchValue('SELECT name FROM files');
        self::assertSame($stored, rawurldecode(substr($header, strpos($header, "UTF-8''") + 7)));
    }

    /**
     * @return array<string, array{string}>
     */
    public static function hostileNames(): array
    {
        return [
            'cr lf and quotes' => ["evil\r\nSet-Cookie: a=b\"; x=\".png"],
            'quote' => ['a"b.png'],
            'long cyrillic' => [str_repeat('я', 296) . '.png'],
            'emoji and html' => ['🎰 <img src=x onerror=alert(1)> Лото.png'],
            'percent and semicolon' => ['100%;filename=evil.png'],
            'path traversal' => ['../../x'],
            'bidi override' => ["gnp.\u{202E}exe"],
        ];
    }

    public function test_the_response_carries_the_security_headers_and_the_stored_type(): void
    {
        $png = Samples::png();
        // The client lies about the type; the answer says what the bytes are.
        $id = $this->uploadAs($this->buyer, $png, 'a.html', 'text/html');

        $response = $this->download($id, $this->buyer);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame('image/png', $response->getHeaderLine('Content-Type'));
        self::assertSame((string) strlen($png), $response->getHeaderLine('Content-Length'));
        self::assertSame('nosniff', $response->getHeaderLine('X-Content-Type-Options'));
        self::assertSame("default-src 'none'; sandbox", $response->getHeaderLine('Content-Security-Policy'));
        self::assertSame('private, no-cache', $response->getHeaderLine('Cache-Control'));
        self::assertStringStartsWith('attachment;', $response->getHeaderLine('Content-Disposition'));
        // Nothing here is a range response.
        self::assertFalse($response->hasHeader('Accept-Ranges'));
        self::assertFalse($response->hasHeader('Content-Range'));
    }

    public function test_a_range_request_is_answered_with_the_whole_file(): void
    {
        $png = Samples::png();
        $id = $this->uploadAs($this->buyer, $png, 'a.png');

        $request = $this->withSessionOf($this->buyer, $this->buildRequest('GET', '/api/files/' . $id))->withHeader('Range', 'bytes=0-3');
        $response = $this->handle($request);

        self::assertSame(200, $response->getStatusCode());
        self::assertSame($png, (string) $response->getBody());
    }

    public function test_the_body_is_streamed_from_the_stored_file_and_not_read_into_memory(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::pngOfLength(300_000), 'big.png');

        $response = $this->download($id, $this->buyer);

        $stored = $this->fetchValue('SELECT path FROM files');
        self::assertIsString($stored);
        // A PSR-7 stream over the open file: the metadata of its resource names the file.
        self::assertSame($this->uploadDir . '/' . $stored, $response->getBody()->getMetadata('uri'));
        self::assertSame(300_000, $response->getBody()->getSize());
    }

    public function test_a_file_whose_bytes_are_gone_is_404(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), 'a.png');
        $path = $this->fetchValue('SELECT path FROM files');
        self::assertIsString($path);
        unlink($this->uploadDir . '/' . $path);

        $response = $this->download($id, $this->buyer);

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
    }

    public function test_a_stored_path_that_leaves_the_upload_directory_is_never_opened(): void
    {
        $outside = $this->workDir . '/secret.txt';
        file_put_contents($outside, 'secret');
        $id = $this->uploadAs($this->buyer, Samples::png(), 'a.png');
        $this->database->pdo()->prepare('UPDATE files SET path = ? WHERE id = ?')->execute(['../secret.txt', $id]);

        $response = $this->download($id, $this->buyer);

        self::assertSame(404, $response->getStatusCode());
        self::assertStringNotContainsString('secret', (string) $response->getBody());
    }

    public function test_a_symbolic_link_in_the_store_is_not_followed(): void
    {
        $outside = $this->workDir . '/secret.png';
        file_put_contents($outside, Samples::png());
        $id = $this->uploadAs($this->buyer, Samples::png(), 'a.png');
        $path = $this->fetchValue('SELECT path FROM files');
        self::assertIsString($path);
        unlink($this->uploadDir . '/' . $path);
        symlink($outside, $this->uploadDir . '/' . $path);

        self::assertSame(404, $this->download($id, $this->buyer)->getStatusCode());
    }

    public function test_non_numeric_ids_are_404(): void
    {
        foreach (['abc', '1abc', '-1', '0', '99999999999999999999999'] as $id) {
            $response = $this->request('GET', '/api/files/' . $id, as: $this->buyer);

            self::assertSame(404, $response->getStatusCode(), $id);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        }
    }

    public function test_file_attached_to_a_task_is_readable_by_whoever_may_view_that_task(): void
    {
        $other = $this->createUser('otto', Role::Buyer);
        $otherDesigner = $this->createUser('dina', Role::Designer);
        $png = Samples::png();
        $id = $this->uploadAs($this->buyer, $png, 'reference.png');

        // Attached to a New task of anna's: the buyer, every designer (the pool) and a manager may view it.
        $this->attachments->attach($id, $this->task(createdBy: $this->buyer->id, assignee: null, status: TaskStatus::New));

        foreach ([$this->buyer, $this->designer, $otherDesigner, $this->manager] as $viewer) {
            $response = $this->download($id, $viewer);

            self::assertSame(200, $response->getStatusCode(), $viewer->username);
            self::assertSame($png, (string) $response->getBody());
        }

        // Another buyer may not view the task, so the file does not exist for them.
        $stranger = $this->download($id, $other);
        self::assertSame(404, $stranger->getStatusCode());
        self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $stranger->getBody());
    }

    public function test_access_follows_the_task_the_file_is_attached_to(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), 'reference.png');
        $otherDesigner = $this->createUser('dina', Role::Designer);

        // The task is assigned to dave: it left the pool, so only he (not dina) may view it.
        $this->attachments->attach($id, $this->task(createdBy: $this->buyer->id, assignee: $this->designer->id, status: TaskStatus::InProgress));

        self::assertSame(200, $this->download($id, $this->designer)->getStatusCode());
        self::assertSame(200, $this->download($id, $this->manager)->getStatusCode());
        self::assertSame(404, $this->download($id, $otherDesigner)->getStatusCode());
        self::assertSame(404, $this->download($id, $this->createUser('otto', Role::Buyer))->getStatusCode());
    }

    public function test_a_file_on_several_tasks_is_readable_through_any_of_them(): void
    {
        $id = $this->uploadAs($this->buyer, Samples::png(), 'reference.png');
        $otherDesigner = $this->createUser('dina', Role::Designer);

        $this->attachments->attach($id, $this->task(createdBy: $this->buyer->id, assignee: $this->designer->id, status: TaskStatus::InProgress));
        self::assertSame(404, $this->download($id, $otherDesigner)->getStatusCode());

        $this->attachments->attach($id, $this->task(createdBy: $this->buyer->id, assignee: null, status: TaskStatus::New));
        self::assertSame(200, $this->download($id, $otherDesigner)->getStatusCode());
    }

    public function test_the_uploader_keeps_access_after_the_file_is_attached(): void
    {
        $id = $this->uploadAs($this->designer, Samples::png(), 'deliverable.png');
        $store = $this->service(FileStore::class);
        $store->attach($id, 1, AttachmentKind::Deliverable, $this->designer);
        // Attached to a task the designer cannot view (somebody else's, already assigned elsewhere).
        $this->attachments->attach($id, $this->task(createdBy: $this->buyer->id, assignee: 999, status: TaskStatus::InProgress));

        self::assertSame(200, $this->download($id, $this->designer)->getStatusCode());
    }

    public function test_the_default_resolver_attaches_nothing(): void
    {
        // Without the fake, the application as wired today knows no task for any file.
        $this->bootApp($this->config);
        $id = $this->uploadAs($this->buyer, Samples::png(), 'a.png');
        $this->service(FileStore::class)->attach($id, 1, AttachmentKind::Reference, $this->buyer);

        self::assertSame(200, $this->download($id, $this->buyer)->getStatusCode());
        self::assertSame(404, $this->download($id, $this->manager)->getStatusCode());
    }

    private function task(int $createdBy, ?int $assignee, TaskStatus $status): Task
    {
        return new Task(1, $createdBy, $assignee, $status, new DateTimeImmutable('2026-02-01 00:00:00'));
    }

    private function uploadAs(User $uploader, string $contents, string $name, ?string $clientType = null): int
    {
        $response = $this->upload($contents, $name, as: $uploader, clientType: $clientType);
        self::assertSame(201, $response->getStatusCode(), (string) $response->getBody());

        $id = $this->fetchValue('SELECT MAX(id) FROM files');

        return is_numeric($id) ? (int) $id : self::fail('The upload stored no row.');
    }

    private function download(int $id, User $as): ResponseInterface
    {
        return $this->request('GET', '/api/files/' . $id, as: $as);
    }

    private function withSessionOf(User $user, ServerRequestInterface $request): ServerRequestInterface
    {
        [$token] = $this->startSession($user);

        return self::withSession($request, $token);
    }
}
