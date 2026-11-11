<?php

declare(strict_types=1);

namespace App\Tests\AiUsage;

use App\AiUsage\AiProviderRepository;
use App\Shared\Db\Database;
use App\Tasks\TaskStatus;
use PDOException;

/**
 * The provider list (GET /api/ai-providers), the tables behind AI usage, and the rule that a provider
 * name is unique whatever its case, also when two requests create it at the same moment.
 */
final class ProvidersTest extends AiUsageTestCase
{
    public function test_every_signed_in_role_gets_the_providers_sorted_by_name(): void
    {
        $zeta = $this->provider('Zeta');
        $alpha = $this->provider('alpha');
        $mid = $this->provider('Midjourney');

        foreach ([$this->anna, $this->dave, $this->mia] as $user) {
            $response = $this->request('GET', '/api/ai-providers', as: $user);

            self::assertSame(200, $response->getStatusCode(), (string) $response->getBody());
            $this->assertMatchesSpec($response);
            self::assertSame(
                ['providers' => [['id' => $alpha, 'name' => 'alpha'], ['id' => $mid, 'name' => 'Midjourney'], ['id' => $zeta, 'name' => 'Zeta']]],
                self::decodeJson($response),
            );
        }
    }

    public function test_an_empty_list_is_a_list(): void
    {
        $response = $this->request('GET', '/api/ai-providers', as: $this->dave);

        self::assertSame(200, $response->getStatusCode());
        $this->assertMatchesSpec($response);
        self::assertSame('{"providers":[]}', (string) $response->getBody());
    }

    public function test_it_needs_a_session(): void
    {
        $response = $this->request('GET', '/api/ai-providers');

        self::assertSame(401, $response->getStatusCode());
        $this->assertMatchesSpec($response);
    }

    public function test_names_are_unique_whatever_the_case(): void
    {
        $this->provider('Runway');

        foreach (['runway', 'RUNWAY', 'RunWay'] as $name) {
            try {
                $this->provider($name);
                self::fail($name . ' was accepted next to Runway.');
            } catch (PDOException $e) {
                self::assertSame('23000', (string) $e->getCode());
            }
        }

        self::assertSame(1, $this->countRows('ai_providers'));
        self::assertSame(
            'utf8mb4_unicode_ci',
            $this->fetchValue("SELECT COLLATION_NAME FROM information_schema.COLUMNS WHERE TABLE_SCHEMA = DATABASE() AND TABLE_NAME = 'ai_providers' AND COLUMN_NAME = 'name'"),
        );
    }

    public function test_schema_of_the_tables(): void
    {
        self::assertSame(
            ['id' => ['int unsigned', 'NO'], 'name' => ['varchar(50)', 'NO']],
            $this->columnsOf('ai_providers'),
        );
        self::assertSame(
            ['id' => ['int unsigned', 'NO'], 'round_id' => ['int unsigned', 'NO'], 'provider_id' => ['int unsigned', 'NO'], 'credits' => ['decimal(12,2)', 'NO']],
            $this->columnsOf('ai_usage'),
        );
    }

    public function test_credits_cannot_be_negative_and_a_provider_in_use_cannot_be_deleted(): void
    {
        $pdo = $this->database->pdo();
        $task = $this->taskInStatus(TaskStatus::UnderReview);
        $round = $this->firstRound($task);
        $provider = $this->provider('Kling');

        try {
            $pdo->prepare('INSERT INTO ai_usage (round_id, provider_id, credits) VALUES (?, ?, ?)')->execute([$round, $provider, '-0.01']);
            self::fail('A negative amount was stored.');
        } catch (PDOException $e) {
            self::assertStringContainsString('CONSTRAINT', $e->getMessage());
        }

        $this->usage($round, 'Kling', '1');
        try {
            $pdo->prepare('DELETE FROM ai_providers WHERE id = ?')->execute([$provider]);
            self::fail('A provider in use was deleted.');
        } catch (PDOException $e) {
            self::assertStringContainsString('foreign key', strtolower($e->getMessage()));
        }

        // Lines go with their round.
        $pdo->prepare('DELETE FROM rounds WHERE id = ?')->execute([$round]);
        self::assertSame(0, $this->countRows('ai_usage'));
    }

    public function test_resolve_reuses_the_stored_spelling(): void
    {
        $id = $this->provider('Adobe Firefly');
        $repository = $this->service(AiProviderRepository::class);

        $this->database->transaction(function () use ($repository, $id): void {
            self::assertSame(['id' => $id, 'name' => 'Adobe Firefly'], $repository->resolve('adobe firefly'));
            $new = $repository->resolve('Pika');
            self::assertSame('Pika', $new['name']);
            self::assertSame($new, $repository->resolve('PIKA'));
        });

        self::assertSame(2, $this->countRows('ai_providers'));
    }

    public function test_a_lost_creation_race_resolves_to_the_winner(): void
    {
        $other = new Database($this->config);
        $repository = new AiProviderRepository($other);

        $other->transaction(function () use ($other, $repository): void {
            // A second connection has read the list while "Pika" does not exist...
            $other->pdo()->query('SELECT COUNT(*) FROM ai_providers');
            self::assertNotFalse($other->pdo()->query('SELECT COUNT(*) FROM ai_providers'));

            // ...the winner creates and commits it...
            $winner = $this->provider('Pika');

            // ...and the loser's INSERT fails on the unique key; it ends up with the winner's row, not an error.
            self::assertSame(['id' => $winner, 'name' => 'Pika'], $repository->resolve('pika'));
        }, readCommitted: true);

        self::assertSame(1, $this->countRows('ai_providers'));
    }
}
