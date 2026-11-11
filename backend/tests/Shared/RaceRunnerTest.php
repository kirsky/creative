<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Identity\Role;
use App\Tests\Support\ApiTestCase;
use App\Tests\Support\RaceRunner;
use RuntimeException;

/**
 * The race harness fails loudly instead of hanging or misreading: a deadline that passes and a
 * process that dies are errors, not missing answers.
 */
final class RaceRunnerTest extends ApiTestCase
{
    public function test_a_deadline_that_passes_fails_the_race(): void
    {
        $user = $this->createUser('dave', Role::Designer);

        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not finish in time');

        RaceRunner::run($this->workDir, [['userId' => $user->id, 'method' => 'GET', 'path' => '/api/tasks']], timeout: 0);
    }

    public function test_a_process_that_dies_fails_the_race(): void
    {
        $this->expectException(RuntimeException::class);
        $this->expectExceptionMessage('did not get ready');

        RaceRunner::run($this->workDir, [['userId' => 999999, 'method' => 'GET', 'path' => '/api/tasks']]);
    }

    public function test_answers_come_back_in_the_order_of_the_requests(): void
    {
        $dave = $this->createUser('dave', Role::Designer);
        $anna = $this->createUser('anna', Role::Buyer);

        $answers = RaceRunner::run($this->workDir, [
            ['userId' => $dave->id, 'method' => 'GET', 'path' => '/api/tasks/999'],
            ['userId' => $anna->id, 'method' => 'POST', 'path' => '/api/tasks/999/rounds', 'body' => ['fileIds' => [1]]],
        ]);

        self::assertSame([404, 404], array_column($answers, 'status'));
    }
}
