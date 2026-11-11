<?php

declare(strict_types=1);

namespace App\History;

use App\Shared\Db\Database;
use InvalidArgumentException;
use JsonException;
use PDO;
use RuntimeException;

/**
 * The field-level audit of brief edits (the task_field_changes table): for every field an edit
 * changed, what it was and what it became. A row belongs to the brief_edited event of the edit,
 * which supplies who and when. Append-only like the event log: this class can record and read,
 * nothing else.
 *
 * Values are stored as JSON, so old and new may be null, a string, a number, a boolean or an array
 * (a list of country codes, say) and come back as they went in.
 */
final class FieldAuditLog
{
    /** Same flags as the event payloads: Cyrillic and emoji stay readable in the table. */
    private const JSON_FLAGS = JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES;

    /** The width of task_field_changes.field. */
    private const FIELD_MAX_LENGTH = 100;

    public function __construct(private readonly Database $database) {}

    /**
     * Appends the changes of one event, all or none, in the given order.
     *
     * @param list<array{field: string, old: mixed, new: mixed}> $changes
     * @throws InvalidArgumentException when a change lacks field, old or new, has an empty or too long
     *     field name, or has a value that cannot be encoded as JSON; nothing is stored then
     */
    public function record(int $eventId, array $changes): void
    {
        $rows = [];
        foreach ($changes as $change) {
            $rows[] = self::encode($change);
        }

        if ($rows === []) {
            return;
        }

        $this->database->transaction(function () use ($eventId, $rows): void {
            $statement = $this->database->pdo()->prepare(
                'INSERT INTO task_field_changes (event_id, field, old_value, new_value) VALUES (?, ?, ?, ?)',
            );

            foreach ($rows as [$field, $old, $new]) {
                $statement->execute([$eventId, $field, $old, $new]);
            }
        });
    }

    /**
     * The changes recorded for an event, in the order they were recorded.
     *
     * @return list<array{field: string, old: mixed, new: mixed}>
     */
    public function forEvent(int $eventId): array
    {
        $statement = $this->database->pdo()->prepare(
            'SELECT field, old_value, new_value FROM task_field_changes WHERE event_id = ? ORDER BY id ASC',
        );
        $statement->execute([$eventId]);

        $changes = [];
        while (($row = $statement->fetch(PDO::FETCH_ASSOC)) !== false) {
            if (!is_string($row['field'] ?? null) || !is_string($row['old_value'] ?? null) || !is_string($row['new_value'] ?? null)) {
                throw new RuntimeException('Unexpected shape of a task_field_changes row.');
            }

            $changes[] = [
                'field' => $row['field'],
                'old' => json_decode($row['old_value'], true, 512, JSON_THROW_ON_ERROR),
                'new' => json_decode($row['new_value'], true, 512, JSON_THROW_ON_ERROR),
            ];
        }

        return $changes;
    }

    /**
     * @param array<array-key, mixed> $change
     * @return array{string, string, string} the field and the JSON of the old and the new value
     */
    private static function encode(array $change): array
    {
        $field = $change['field'] ?? null;

        if (!is_string($field) || $field === '' || mb_strlen($field) > self::FIELD_MAX_LENGTH) {
            throw new InvalidArgumentException(sprintf('A field change needs a field name of 1 to %d characters.', self::FIELD_MAX_LENGTH));
        }

        // array_key_exists: a null old or new value is a value, not a missing one.
        if (!array_key_exists('old', $change) || !array_key_exists('new', $change)) {
            throw new InvalidArgumentException(sprintf('The change of field "%s" needs both "old" and "new".', $field));
        }

        try {
            return [
                $field,
                json_encode($change['old'], self::JSON_FLAGS),
                json_encode($change['new'], self::JSON_FLAGS),
            ];
        } catch (JsonException $e) {
            throw new InvalidArgumentException(sprintf('The values of field "%s" must be encodable as JSON: %s', $field, $e->getMessage()), 0, $e);
        }
    }
}
