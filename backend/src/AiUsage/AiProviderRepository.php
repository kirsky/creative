<?php

declare(strict_types=1);

namespace App\AiUsage;

use App\Shared\Db\Database;
use App\Shared\Db\Row;
use PDO;
use PDOException;
use RuntimeException;

/**
 * The managed list of AI providers (ai_providers). Names are unique whatever their case, because the
 * column's collation compares that way, and a provider is created when a designer first uses it.
 */
final class AiProviderRepository
{
    /** MariaDB/MySQL error number of a duplicate key. */
    private const int DUPLICATE_KEY = 1062;

    public function __construct(private readonly Database $database) {}

    /**
     * Every provider, sorted by name.
     *
     * @return list<array{id: int, name: string}>
     */
    public function all(): array
    {
        $statement = $this->database->pdo()->query('SELECT id, name FROM ai_providers ORDER BY name ASC, id ASC');
        if ($statement === false) {
            throw new RuntimeException('Cannot read the AI providers.');
        }

        $providers = [];
        foreach ($statement->fetchAll(PDO::FETCH_ASSOC) as $row) {
            $providers[] = ['id' => Row::int($row, 'id'), 'name' => Row::string($row, 'name')];
        }

        return $providers;
    }

    /**
     * The provider called $name, matched without regard to case, created if there is none. The
     * provider returned carries the spelling that is stored, which is the first one anybody used.
     *
     * Call it inside the caller's Database::transaction(), so that the provider is rolled back with
     * the request that needed it. $name must be normalised already (UsageLinesParser::normalizeName).
     *
     * Two requests may create the same new provider at the same moment. The second INSERT waits for
     * the first one's transaction and then fails on the UNIQUE key; that is not an error, the
     * provider exists now. It is read again with a locking read, because a plain SELECT in a
     * REPEATABLE READ transaction keeps showing the snapshot from before the other request committed.
     *
     * @return array{id: int, name: string}
     * @throws PDOException for any database error other than that duplicate key
     */
    public function resolve(string $name): array
    {
        $found = $this->find($name, '');
        if ($found !== null) {
            return $found;
        }

        try {
            $pdo = $this->database->pdo();
            $pdo->prepare('INSERT INTO ai_providers (name) VALUES (?)')->execute([$name]);

            return ['id' => (int) $pdo->lastInsertId(), 'name' => $name];
        } catch (PDOException $e) {
            if (($e->errorInfo[1] ?? null) !== self::DUPLICATE_KEY) {
                throw $e;
            }
        }

        return $this->find($name, ' LOCK IN SHARE MODE')
            ?? throw new RuntimeException(sprintf('The AI provider "%s" is neither found nor creatable.', $name));
    }

    /**
     * @return array{id: int, name: string}|null
     */
    private function find(string $name, string $lock): ?array
    {
        $statement = $this->database->pdo()->prepare('SELECT id, name FROM ai_providers WHERE name = ?' . $lock);
        $statement->execute([$name]);
        $row = $statement->fetch(PDO::FETCH_ASSOC);

        return $row === false ? null : ['id' => Row::int($row, 'id'), 'name' => Row::string($row, 'name')];
    }
}
