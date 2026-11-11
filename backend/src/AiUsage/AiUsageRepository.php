<?php

declare(strict_types=1);

namespace App\AiUsage;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use LogicException;
use PDO;

/**
 * Writes and reads the lines of AI usage of a round (ai_usage). Nothing here opens a transaction:
 * the services wrap each change in one, after locking the task.
 */
final class AiUsageRepository
{
    public function __construct(
        private readonly Database $database,
        private readonly AiProviderRepository $providers,
    ) {}

    /**
     * Matches the lines with the list of providers, creating the ones that are new, and returns them
     * with the stored spelling of their provider.
     *
     * The providers are looked up in alphabetical order, whatever the order of the lines. Two
     * requests that create the same new providers then lock them in the same order, so they wait for
     * each other and cannot deadlock.
     *
     * @param list<UsageLine> $lines
     * @return list<array{providerId: int, provider: string, credits: string}>
     */
    public function resolve(array $lines): array
    {
        $names = [];
        foreach ($lines as $line) {
            $names[mb_strtolower($line->provider, 'UTF-8')] ??= $line->provider;
        }
        ksort($names, SORT_STRING);

        $providers = [];
        foreach ($names as $key => $name) {
            $providers[$key] = $this->providers->resolve($name);
        }

        $resolved = [];
        foreach ($lines as $line) {
            $provider = $providers[mb_strtolower($line->provider, 'UTF-8')] ?? null;
            if ($provider === null) {
                throw new LogicException('A line has no resolved provider.');
            }
            $resolved[] = ['providerId' => $provider['id'], 'provider' => $provider['name'], 'credits' => $line->credits];
        }

        return $resolved;
    }

    /**
     * Stores lines (as resolve() returns them) for a round, in this order.
     *
     * @param list<array{providerId: int, provider: string, credits: string}> $lines
     */
    public function insert(int $roundId, array $lines): void
    {
        $statement = $this->database->pdo()->prepare('INSERT INTO ai_usage (round_id, provider_id, credits) VALUES (?, ?, ?)');
        foreach ($lines as $line) {
            $statement->execute([$roundId, $line['providerId'], $line['credits']]);
        }
    }

    public function deleteAll(int $roundId): void
    {
        $this->database->pdo()->prepare('DELETE FROM ai_usage WHERE round_id = ?')->execute([$roundId]);
    }

    /**
     * The lines of a round in insertion order.
     *
     * @return list<array{providerId: int, provider: string, credits: string}>
     */
    public function forRound(int $roundId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT u.provider_id, p.name, u.credits FROM ai_usage u JOIN ai_providers p ON p.id = u.provider_id WHERE u.round_id = ? ORDER BY u.id',
        );
        $statement->execute([$roundId]);

        $lines = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $lines[] = ['providerId' => Row::int($row, 'provider_id'), 'provider' => Row::string($row, 'name'), 'credits' => Row::string($row, 'credits')];
        }

        return $lines;
    }
}
