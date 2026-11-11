<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Http\ApiError;
use LogicException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Checks one free-text value of a request (a reason, a note) and says what is wrong as codes.
 *
 * Both forms take the raw JSON value (null when the field was not sent) and answer 422
 * validation_failed on the field named by the caller: `invalid` for anything but a string,
 * `too_long` (params.max) beyond 2000 characters (MAX). The text is returned as written.
 */
final class FreeText
{
    public const int MAX = 2000;

    public function __construct(private readonly Validator $validator) {}

    /**
     * Text that must be there: `required` when it is missing, null, empty or only whitespace.
     *
     * @throws ApiError 422 validation_failed
     */
    public function required(mixed $value, string $field): string
    {
        $data = $this->validator->validate([$field => $value], new Assert\Collection([
            $field => new Assert\Required(new Assert\Sequentially([
                new Assert\NotBlank(message: 'required', normalizer: 'trim'),
                new Assert\Type('string', message: 'invalid'),
                new Assert\Length(max: self::MAX, maxMessage: 'too_long'),
            ])),
        ]));

        return is_string($data[$field] ?? null) ? $data[$field] : throw new LogicException('A validated text is not a string.');
    }

    /**
     * Text that may be left out: null for a missing value, null, an empty string or only whitespace.
     *
     * @throws ApiError 422 validation_failed
     */
    public function optional(mixed $value, string $field): ?string
    {
        if ($value === null) {
            return null;
        }

        $data = $this->validator->validate([$field => $value], new Assert\Collection([
            $field => new Assert\Required(new Assert\Sequentially([
                new Assert\Type('string', message: 'invalid'),
                new Assert\Length(max: self::MAX, maxMessage: 'too_long'),
            ])),
        ]));

        $text = $data[$field] ?? null;

        return is_string($text) && trim($text) !== '' ? $text : null;
    }
}
