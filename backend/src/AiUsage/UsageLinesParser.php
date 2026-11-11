<?php

declare(strict_types=1);

namespace App\AiUsage;

use App\Shared\Http\ApiError;

/**
 * Turns the `aiUsage` list of a submission, or the `lines` of a replacement, into UsageLines, or says
 * what is wrong with it as codes.
 *
 * The list: invalid (not a list), too_many (more than 20, params.max 20; zero lines is valid, it
 * means no AI was used). A line is an object with exactly `provider` and `credits`: the line
 * itself is invalid when it is not an object, a missing key is required, any other key unknown_field.
 * `provider`: invalid (not a string, or with a control character), required (nothing left after
 * trimming), too_long (more than 50 characters, params.max 50). `credits`: invalid (not a JSON
 * string), invalid_amount (a string that is not ^\d{1,7}(\.\d{1,2})?$, so 0 to 9999999.99 with at
 * most two decimals and no sign, exponent or space). Fields are named under the list's own name:
 * "aiUsage.0.credits".
 */
final class UsageLinesParser
{
    public const int LINES_MAX = 20;

    public const int PROVIDER_MAX = 50;

    /** The field name of a key that is not safe to echo back. */
    private const string UNREADABLE_FIELD = '_invalid';

    /**
     * @param mixed $raw the decoded JSON value
     * @param string $field the name of the list in the request ("aiUsage", "lines")
     * @return list<UsageLine>
     * @throws ApiError 422 validation_failed
     */
    public function parse(mixed $raw, string $field): array
    {
        $errors = [];
        $lines = $this->check($raw, $field, $errors);

        if ($errors !== []) {
            throw new ApiError(422, 'validation_failed', $errors);
        }

        return $lines;
    }

    /**
     * Like parse(), but adds the problems to $errors instead of throwing, so that a caller can report
     * them together with those of the rest of the request. The lines returned are only meaningful
     * when nothing was added.
     *
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $errors
     * @return list<UsageLine>
     */
    public function check(mixed $raw, string $field, array &$errors): array
    {
        if (!is_array($raw) || !array_is_list($raw)) {
            $errors[] = ['field' => $field, 'code' => 'invalid', 'params' => []];

            return [];
        }

        if (count($raw) > self::LINES_MAX) {
            $errors[] = ['field' => $field, 'code' => 'too_many', 'params' => ['max' => self::LINES_MAX]];

            return [];
        }

        $lines = [];
        foreach ($raw as $index => $item) {
            $line = $this->line($item, $field . '.' . $index, $errors);
            if ($line !== null) {
                $lines[] = $line;
            }
        }

        return $lines;
    }

    /**
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $errors
     */
    private function line(mixed $item, string $path, array &$errors): ?UsageLine
    {
        if (!is_array($item) || ($item !== [] && array_is_list($item))) {
            $errors[] = ['field' => $path, 'code' => 'invalid', 'params' => []];

            return null;
        }

        $before = count($errors);

        foreach (array_keys($item) as $key) {
            if ($key !== 'provider' && $key !== 'credits') {
                $errors[] = ['field' => $path . '.' . self::safeKey((string) $key), 'code' => 'unknown_field', 'params' => []];
            }
        }

        $provider = $this->provider($item, $path . '.provider', $errors);
        $credits = $this->credits($item, $path . '.credits', $errors);

        return count($errors) === $before && $provider !== null && $credits !== null ? new UsageLine($provider, $credits) : null;
    }

    /**
     * @param array<array-key, mixed> $item
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $errors
     */
    private function provider(array $item, string $field, array &$errors): ?string
    {
        if (!array_key_exists('provider', $item)) {
            $errors[] = ['field' => $field, 'code' => 'required', 'params' => []];

            return null;
        }

        $name = self::normalizeName($item['provider']);
        if ($name === null) {
            $errors[] = ['field' => $field, 'code' => 'invalid', 'params' => []];

            return null;
        }

        if ($name === '') {
            $errors[] = ['field' => $field, 'code' => 'required', 'params' => []];

            return null;
        }

        if (mb_strlen($name, 'UTF-8') > self::PROVIDER_MAX) {
            $errors[] = ['field' => $field, 'code' => 'too_long', 'params' => ['max' => self::PROVIDER_MAX]];

            return null;
        }

        return $name;
    }

    /**
     * @param array<array-key, mixed> $item
     * @param list<array{field: string, code: string, params: array<string, mixed>}> $errors
     */
    private function credits(array $item, string $field, array &$errors): ?string
    {
        if (!array_key_exists('credits', $item)) {
            $errors[] = ['field' => $field, 'code' => 'required', 'params' => []];

            return null;
        }

        $value = $item['credits'];
        if (!is_string($value)) {
            $errors[] = ['field' => $field, 'code' => 'invalid', 'params' => []];

            return null;
        }

        // \z and no /u: ASCII digits only, and a trailing newline is not accepted.
        if (preg_match('/^\d{1,7}(?:\.\d{1,2})?\z/', $value) !== 1) {
            $errors[] = ['field' => $field, 'code' => 'invalid_amount', 'params' => []];

            return null;
        }

        [$whole, $fraction] = [...explode('.', $value, 2), ''];

        return (ltrim($whole, '0') ?: '0') . '.' . str_pad($fraction, 2, '0');
    }

    /**
     * A provider name as the list keeps it: trimmed, every run of white space (Unicode included)
     * one plain space. Null when the value is not a string, is not valid UTF-8 or has a control
     * character that is not white space.
     */
    public static function normalizeName(mixed $value): ?string
    {
        if (!is_string($value) || !mb_check_encoding($value, 'UTF-8')) {
            return null;
        }

        $collapsed = preg_replace('/\s+/u', ' ', $value);
        if ($collapsed === null || preg_match('/[\p{Cc}\p{Cs}]/u', $collapsed) === 1) {
            return null;
        }

        return trim($collapsed, ' ');
    }

    private static function safeKey(string $key): string
    {
        return preg_match('/^[A-Za-z0-9_]{1,50}$/', $key) === 1 ? $key : self::UNREADABLE_FIELD;
    }
}
