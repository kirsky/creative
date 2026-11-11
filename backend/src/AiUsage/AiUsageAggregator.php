<?php

declare(strict_types=1);

namespace App\AiUsage;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use DateTimeZone;
use PDO;

/**
 * Sums of AI credits for the dashboard: overall, by provider, and by designer with their providers.
 *
 * A line counts for the designer who submitted its round (rounds.submitted_by) and in the period of
 * the round's submission time (rounds.submitted_at). All the arithmetic is DECIMAL arithmetic in SQL;
 * the sums travel as strings with two decimals and are never converted to floats. Cancelled tasks
 * count too: their credits were spent. Providers and designers without any line do not appear.
 */
final class AiUsageAggregator
{
    private const string DATETIME = 'Y-m-d H:i:s.u';

    public function __construct(private readonly Database $database) {}

    /**
     * @return string total credits of the period, "0.00" when there is none
     */
    public function total(Period $period): string
    {
        [$where, $parameters] = self::window($period);
        $statement = $this->database->pdo()->prepare(
            'SELECT CAST(COALESCE(SUM(u.credits), 0) AS DECIMAL(20,2)) AS total
             FROM ai_usage u JOIN rounds r ON r.id = u.round_id' . $where,
        );
        $statement->execute($parameters);

        return Row::string($statement->fetch(PDO::FETCH_ASSOC), 'total');
    }

    /**
     * Credits per provider, the most first, equal sums by provider name.
     *
     * @return list<array{provider: string, credits: string}>
     */
    public function byProvider(Period $period): array
    {
        [$where, $parameters] = self::window($period);
        $statement = $this->database->pdo()->prepare(
            'SELECT p.name, CAST(SUM(u.credits) AS DECIMAL(20,2)) AS credits
             FROM ai_usage u
             JOIN rounds r ON r.id = u.round_id
             JOIN ai_providers p ON p.id = u.provider_id' . $where . '
             GROUP BY p.id, p.name
             ORDER BY SUM(u.credits) DESC, p.name ASC, p.id ASC',
        );
        $statement->execute($parameters);

        $providers = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $providers[] = ['provider' => Row::string($row, 'name'), 'credits' => Row::string($row, 'credits')];
        }

        return $providers;
    }

    /**
     * Credits per designer, the most first (equal totals by name, then id), each with the designer's
     * credits per provider in the order of byProvider(). One query: the designer's total is a window
     * sum over the per-provider sums, so that the two cannot disagree.
     *
     * @return list<array{designerId: int, name: string, total: string, byProvider: list<array{provider: string, credits: string}>}>
     */
    public function byDesigner(Period $period): array
    {
        [$where, $parameters] = self::window($period);
        $statement = $this->database->pdo()->prepare(
            'SELECT r.submitted_by AS designer_id, d.display_name AS designer_name, p.name AS provider,
                    CAST(SUM(u.credits) AS DECIMAL(20,2)) AS credits,
                    CAST(SUM(SUM(u.credits)) OVER (PARTITION BY r.submitted_by) AS DECIMAL(20,2)) AS designer_total
             FROM ai_usage u
             JOIN rounds r ON r.id = u.round_id
             JOIN users d ON d.id = r.submitted_by
             JOIN ai_providers p ON p.id = u.provider_id' . $where . '
             GROUP BY r.submitted_by, d.display_name, p.id, p.name
             ORDER BY designer_total DESC, d.display_name ASC, r.submitted_by ASC, SUM(u.credits) DESC, p.name ASC, p.id ASC',
        );
        $statement->execute($parameters);

        $designers = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $id = Row::int($row, 'designer_id');
            $designers[$id] ??= [
                'designerId' => $id,
                'name' => Row::string($row, 'designer_name'),
                'total' => Row::string($row, 'designer_total'),
                'byProvider' => [],
            ];
            $designers[$id]['byProvider'][] = ['provider' => Row::string($row, 'provider'), 'credits' => Row::string($row, 'credits')];
        }

        return array_values($designers);
    }

    /**
     * @return array{string, list<string>} the WHERE clause (with its leading space, or none) and its parameters
     */
    private static function window(Period $period): array
    {
        if ($period->from === null) {
            return ['', []];
        }

        return [' WHERE r.submitted_at >= ?', [$period->from->setTimezone(new DateTimeZone('UTC'))->format(self::DATETIME)]];
    }
}
