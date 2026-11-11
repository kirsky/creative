<?php

declare(strict_types=1);

namespace App\Tests\Tasks;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Files\Samples;
use App\Tests\Support\ApiTestCase;
use Psr\Http\Message\ResponseInterface;

/**
 * The real AttachedTaskResolver at work: a file attached to a task as a reference is readable by
 * whoever may view that task, through GET /api/files/{id}, and does not exist for anybody else.
 */
final class ReferenceFileAccessTest extends ApiTestCase
{
    private User $anna;

    private User $dave;

    private User $dina;

    private User $mia;

    protected function setUp(): void
    {
        parent::setUp();

        $this->anna = $this->createUser('anna', Role::Buyer);
        $this->dave = $this->createUser('dave', Role::Designer);
        $this->dina = $this->createUser('dina', Role::Designer);
        $this->mia = $this->createUser('mia', Role::Manager);
    }

    public function test_a_reference_of_a_new_task_is_readable_by_creator_every_designer_and_manager(): void
    {
        [$fileId] = $this->createTaskWithReference();

        foreach ([$this->anna, $this->dave, $this->dina, $this->mia] as $viewer) {
            $response = $this->download($fileId, $viewer);

            self::assertSame(200, $response->getStatusCode(), $viewer->username);
            self::assertSame(Samples::png(), (string) $response->getBody());
        }
    }

    public function test_it_does_not_exist_for_a_buyer_who_cannot_view_the_task(): void
    {
        [$fileId] = $this->createTaskWithReference();

        $response = $this->download($fileId, $this->createUser('otto', Role::Buyer));

        self::assertSame(404, $response->getStatusCode());
        self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        $this->assertMatchesSpec($response);
    }

    public function test_once_assigned_only_the_assigned_designer_keeps_access(): void
    {
        [$fileId, $taskId] = $this->createTaskWithReference();
        $this->database->pdo()->prepare("UPDATE tasks SET status = 'in_progress', assignee_id = ? WHERE id = ?")->execute([$this->dave->id, $taskId]);

        self::assertSame(200, $this->download($fileId, $this->dave)->getStatusCode());
        self::assertSame(200, $this->download($fileId, $this->anna)->getStatusCode());
        self::assertSame(200, $this->download($fileId, $this->mia)->getStatusCode());
        self::assertSame(404, $this->download($fileId, $this->dina)->getStatusCode());
    }

    public function test_a_file_that_is_attached_to_nothing_stays_private_to_its_uploader(): void
    {
        $upload = $this->upload(Samples::png(), 'a.png', as: $this->anna);
        $id = self::jsonAt($upload, 'id');
        self::assertIsInt($id);

        self::assertSame(200, $this->download($id, $this->anna)->getStatusCode());
        self::assertSame(404, $this->download($id, $this->mia)->getStatusCode());
        self::assertSame(404, $this->download($id, $this->dave)->getStatusCode());
    }

    public function test_a_task_row_is_all_the_resolver_needs(): void
    {
        $upload = $this->upload(Samples::png(), 'a.png', as: $this->anna);
        $fileId = self::jsonAt($upload, 'id');
        self::assertIsInt($fileId);
        $taskId = $this->insertTask($this->anna, status: TaskStatus::Completed, assignee: $this->dave);
        $this->database->pdo()->prepare("INSERT INTO file_attachments (file_id, task_id, kind) VALUES (?, ?, 'deliverable')")->execute([$fileId, $taskId]);

        // A deliverable of a finished task: the assignee and the manager can read it, another designer cannot.
        self::assertSame(200, $this->download($fileId, $this->dave)->getStatusCode());
        self::assertSame(200, $this->download($fileId, $this->mia)->getStatusCode());
        self::assertSame(404, $this->download($fileId, $this->dina)->getStatusCode());
    }

    /**
     * Creates a task through the API with one uploaded reference file.
     *
     * @return array{int, int} the file id and the task id
     */
    private function createTaskWithReference(): array
    {
        $upload = $this->upload(Samples::png(), 'brief.png', as: $this->anna);
        $fileId = self::jsonAt($upload, 'id');
        self::assertIsInt($fileId);

        $created = $this->request('POST', '/api/tasks', [
            'offer' => 'Лото Клуб',
            'geo' => ['US'],
            'creativeType' => 'video',
            'quantity' => 1,
            'description' => 'See the reference',
            'priority' => 'normal',
            'deadline' => '2026-01-20T10:00:00Z',
            'references' => [['type' => 'file', 'fileId' => $fileId]],
        ], $this->anna);
        self::assertSame(201, $created->getStatusCode(), (string) $created->getBody());
        $taskId = self::jsonAt($created, 'id');
        self::assertIsInt($taskId);

        return [$fileId, $taskId];
    }

    private function download(int $id, User $as): ResponseInterface
    {
        return $this->request('GET', '/api/files/' . $id, as: $as);
    }
}
