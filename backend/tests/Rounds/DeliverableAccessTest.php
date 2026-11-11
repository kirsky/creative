<?php

declare(strict_types=1);

namespace App\Tests\Rounds;

use App\Identity\Role;
use App\Identity\User;
use App\Tasks\TaskStatus;
use App\Tests\Files\Samples;
use Psr\Http\Message\ResponseInterface;

/**
 * A deliverable is a file like any other: GET /api/files/{id} serves it to its uploader and to
 * everybody who may view its task, and it does not exist for anybody else (404, never 403).
 */
final class DeliverableAccessTest extends RoundsTestCase
{
    public function test_a_delivered_file_is_readable_by_whoever_can_view_the_task(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        self::assertSame(201, $this->submit($id, [$file])->getStatusCode());

        foreach ([$this->dave, $this->anna, $this->mia] as $viewer) {
            $response = $this->download($file, $viewer);

            self::assertSame(200, $response->getStatusCode(), $viewer->username);
            self::assertSame(Samples::png(), (string) $response->getBody(), $viewer->username);
        }
    }

    public function test_it_does_not_exist_for_users_who_cannot_view_the_task(): void
    {
        $id = $this->taskOfDave();
        $file = $this->uploadFor($this->dave);
        self::assertSame(201, $this->submit($id, [$file])->getStatusCode());

        foreach ([$this->eve, $this->createUser('otto', Role::Buyer)] as $stranger) {
            $response = $this->download($file, $stranger);

            self::assertSame(404, $response->getStatusCode(), $stranger->username);
            $this->assertMatchesSpec($response);
            self::assertSame('{"error":{"code":"not_found","fields":[]}}', (string) $response->getBody());
        }
    }

    public function test_a_file_chosen_for_a_round_that_failed_stays_private(): void
    {
        $id = $this->taskOfDave(TaskStatus::UnderReview);
        $file = $this->uploadFor($this->dave);
        // Refused (the task takes no submission): the file was not attached to the task.
        self::assertSame(409, $this->submit($id, [$file])->getStatusCode());

        self::assertSame(200, $this->download($file, $this->dave)->getStatusCode());
        self::assertSame(404, $this->download($file, $this->anna)->getStatusCode());
        self::assertSame(404, $this->download($file, $this->mia)->getStatusCode());
    }

    public function test_a_buyer_cannot_read_the_deliverables_of_another_buyers_task(): void
    {
        $otto = $this->createUser('otto', Role::Buyer);
        $id = $this->insertTask($otto, status: TaskStatus::InProgress, assignee: $this->dave);
        $file = $this->uploadFor($this->dave);
        self::assertSame(201, $this->submit($id, [$file])->getStatusCode());

        self::assertSame(200, $this->download($file, $otto)->getStatusCode());
        self::assertSame(404, $this->download($file, $this->anna)->getStatusCode());
    }

    public function test_after_a_release_another_designer_reads_earlier_rounds_files(): void
    {
        $id = $this->taskOfDave();
        $this->openAssignment($id);
        $first = $this->uploadFor($this->dave, 'round1.png');
        self::assertSame(201, $this->submit($id, [$first])->getStatusCode());
        self::assertSame(200, $this->requestRevisions($id)->getStatusCode());
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/release', as: $this->dave)->getStatusCode());
        $otto = $this->createUser('otto', Role::Buyer);

        // Back in the pool (spec section 4.3): the next designer continues from the earlier rounds,
        // so before and after picking it up they can read the files already delivered.
        self::assertSame(200, $this->download($first, $this->eve)->getStatusCode(), 'in the pool');
        self::assertSame(200, $this->request('POST', '/api/tasks/' . $id . '/pickup', as: $this->eve)->getStatusCode());
        $response = $this->download($first, $this->eve);
        self::assertSame(200, $response->getStatusCode(), 'after pick-up');
        self::assertSame(Samples::png(), (string) $response->getBody());

        // The designer who uploaded them keeps reading their own files, though the task is no longer theirs...
        self::assertSame(404, $this->request('GET', '/api/tasks/' . $id, as: $this->dave)->getStatusCode());
        self::assertSame(200, $this->download($first, $this->dave)->getStatusCode(), 'uploader');
        // ...the creating buyer and the manager read them, and a buyer who did not create the task cannot.
        self::assertSame(200, $this->download($first, $this->anna)->getStatusCode());
        self::assertSame(200, $this->download($first, $this->mia)->getStatusCode());
        $stranger = $this->download($first, $otto);
        self::assertSame(404, $stranger->getStatusCode());
        $this->assertMatchesSpec($stranger);
    }

    private function download(int $id, User $as): ResponseInterface
    {
        return $this->request('GET', '/api/files/' . $id, as: $as);
    }
}
