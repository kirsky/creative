<?php

declare(strict_types=1);

namespace App\Shared\Db;

use RuntimeException;

/**
 * Reads typed values out of a database row. PDO hands rows over as mixed; a row that does not have
 * the shape the query promised is a bug, and these throw instead of letting a wrong type travel on.
 * Integers may arrive as numeric strings, depending on the driver; both are accepted.
 */
final class Row
{
    public static function int(mixed $row, string $column): int
    {
        $value = self::value($row, $column);

        return is_numeric($value) ? (int) $value : throw self::bad($column, 'an integer');
    }

    public static function nullableInt(mixed $row, string $column): ?int
    {
        return self::value($row, $column) === null ? null : self::int($row, $column);
    }

    public static function string(mixed $row, string $column): string
    {
        $value = self::value($row, $column);

        return is_string($value) ? $value : throw self::bad($column, 'a string');
    }

    public static function nullableString(mixed $row, string $column): ?string
    {
        return self::value($row, $column) === null ? null : self::string($row, $column);
    }

    private static function value(mixed $row, string $column): mixed
    {
        if (!is_array($row) || !array_key_exists($column, $row)) {
            throw new RuntimeException(sprintf('A database row has no column "%s".', $column));
        }

        return $row[$column];
    }

    private static function bad(string $column, string $expected): RuntimeException
    {
        return new RuntimeException(sprintf('Column "%s" of a database row is not %s.', $column, $expected));
    }
}
