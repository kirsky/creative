<?php

declare(strict_types=1);

namespace App\Shared\Validation;

use App\Shared\Http\ApiError;
use Symfony\Component\Validator\Constraints\Collection;
use Symfony\Component\Validator\Constraints\Count;
use Symfony\Component\Validator\Constraints\Length;
use Symfony\Component\Validator\Constraints\Range;
use Symfony\Component\Validator\ConstraintViolationInterface;
use Symfony\Component\Validator\Validation;
use Symfony\Component\Validator\Validator\ValidatorInterface;

/**
 * Validates request data and reports problems as codes, never sentences.
 *
 * The constraint `message:` values ARE the codes: NotBlank(message: 'required'),
 * Length(min: 2, minMessage: 'too_short'), Range(notInRangeMessage: 'out_of_range'), and so on.
 * A violation becomes {field, code, params}; the frontend translates the code.
 */
final class Validator
{
    /** Reported instead of a client-supplied field name that is not safe to echo. */
    private const UNREADABLE_FIELD = '_invalid';

    private const MAX_FIELD_NAME_LENGTH = 100;

    private readonly ValidatorInterface $validator;

    public function __construct(?ValidatorInterface $validator = null)
    {
        $this->validator = $validator ?? Validation::createValidator();
    }

    /**
     * @param array<array-key, mixed> $data decoded request data (a urlencoded body can have integer keys)
     * @return array<array-key, mixed> the data, unchanged
     * @throws ApiError 422 validation_failed listing every violation
     */
    public function validate(array $data, Collection $rules): array
    {
        $violations = $this->validator->validate($data, $rules);

        if (count($violations) === 0) {
            return $data;
        }

        $fields = [];
        foreach ($violations as $violation) {
            $fields[] = [
                'field' => self::field($violation->getPropertyPath()),
                'code' => self::code($violation),
                'params' => self::params($violation),
            ];
        }

        throw new ApiError(422, 'validation_failed', $fields);
    }

    /**
     * "[links][1]" becomes "links.1".
     *
     * Field names that were not declared in the rules are whatever the client sent as a key, and a
     * urlencoded body can carry any bytes there. Such a name is echoed in the error body only if it
     * is readable text of sane length; otherwise the fixed name "_invalid" stands in for it.
     */
    private static function field(string $propertyPath): string
    {
        $field = $propertyPath;
        if (preg_match_all('/\[([^\]]*)\]/', $propertyPath, $matches) > 0 && implode('', $matches[0]) === $propertyPath) {
            $field = implode('.', $matches[1]);
        }

        $readable = mb_check_encoding($field, 'UTF-8')
            && preg_match('/\p{Cc}/u', $field) !== 1
            && mb_strlen($field, 'UTF-8') <= self::MAX_FIELD_NAME_LENGTH;

        return $readable ? $field : self::UNREADABLE_FIELD;
    }

    private static function code(ConstraintViolationInterface $violation): string
    {
        $code = match ($violation->getCode()) {
            Collection::MISSING_FIELD_ERROR => 'required',
            Collection::NO_SUCH_FIELD_ERROR => 'unknown_field',
            default => $violation->getMessageTemplate(),
        };

        // A constraint written without a code message would put English prose on the wire; send a neutral code instead.
        return preg_match('/^[a-z][a-z0-9_]*$/', $code) === 1 ? $code : 'invalid';
    }

    /**
     * @return array<string, int|float>
     */
    private static function params(ConstraintViolationInterface $violation): array
    {
        $constraint = $violation->getConstraint();
        $params = [];

        if ($constraint instanceof Length || $constraint instanceof Range || $constraint instanceof Count) {
            foreach (['min' => $constraint->min, 'max' => $constraint->max] as $name => $bound) {
                if (is_int($bound) || is_float($bound)) {
                    $params[$name] = $bound;
                }
            }
        }

        return $params;
    }
}
