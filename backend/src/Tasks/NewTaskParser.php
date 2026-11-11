<?php

declare(strict_types=1);

namespace App\Tasks;

use App\Shared\Clock;
use App\Shared\Http\ApiError;
use App\Shared\Validation\Validator;
use App\Tasks\Geo\GeoCodes;
use DateTimeImmutable;
use DateTimeZone;
use Exception;
use RuntimeException;
use Symfony\Component\Validator\Constraints as Assert;
use Symfony\Component\Validator\Context\ExecutionContextInterface;

/**
 * Turns the JSON of POST /api/tasks into a NewTask, or says what is wrong with it as codes
 * (required, too_short, too_long, out_of_range, invalid_choice, invalid_url, must_be_future, invalid).
 *
 * Every problem is reported at once. The offer is trimmed; the description is kept as written.
 * The deadline must carry its zone (Z or an offset) and be after the Clock's now; it is stored in UTC.
 */
final class NewTaskParser
{
    public const int OFFER_MIN = 2;

    public const int OFFER_MAX = 100;

    public const int QUANTITY_MIN = 1;

    public const int QUANTITY_MAX = 100;

    public const int DESCRIPTION_MAX = 10000;

    public const int URL_MAX = 2000;

    public const int REFERENCES_MAX = 50;

    private const string DEADLINE_PATTERN = '/^(\d{4}-\d{2}-\d{2})T(\d{2}:\d{2})(?::(\d{2})(?:\.\d{1,9})?)?(?:Z|[+-]\d{2}:\d{2})$/';

    private const string URL_PATTERN = '~^https?://(?:\[[0-9a-f:.]+\]|[\p{L}\p{N}_.-]+)(?::\d{1,5})?(?:[/?#]\S*)?$~iu';

    public function __construct(
        private readonly Validator $validator,
        private readonly Clock $clock,
    ) {}

    /**
     * @param array<array-key, mixed> $input decoded request data
     * @throws ApiError 422 validation_failed
     */
    public function parse(array $input): NewTask
    {
        $now = $this->clock->now();
        $data = $this->validator->validate($input, $this->rules($now));

        $deadline = self::deadline($data['deadline'] ?? null) ?? throw new RuntimeException('A validated deadline did not parse.');
        $geo = $data['geo'] ?? null;
        $references = $data['references'] ?? [];

        return new NewTask(
            trim(self::string($data['offer'] ?? null)),
            is_array($geo) ? self::geo($geo) : throw new RuntimeException('A validated geo is not a list.'),
            CreativeType::from(self::string($data['creativeType'] ?? null)),
            is_int($data['quantity'] ?? null) ? $data['quantity'] : throw new RuntimeException('A validated quantity is not an integer.'),
            self::string($data['description'] ?? null),
            Priority::from(self::string($data['priority'] ?? null)),
            $deadline,
            is_array($references) ? self::references($references) : [],
        );
    }

    private function rules(DateTimeImmutable $now): Assert\Collection
    {
        return new Assert\Collection([
            'offer' => new Assert\Sequentially([
                new Assert\NotBlank(message: 'required', normalizer: 'trim'),
                new Assert\Type('string', message: 'invalid'),
                // Two constraints, so that each violation reports only the bound it broke.
                new Assert\Length(min: self::OFFER_MIN, minMessage: 'too_short', normalizer: 'trim'),
                new Assert\Length(max: self::OFFER_MAX, maxMessage: 'too_long', normalizer: 'trim'),
            ]),
            'geo' => new Assert\Callback(self::checkGeo(...)),
            'creativeType' => new Assert\Sequentially([
                new Assert\NotBlank(message: 'required'),
                new Assert\Choice(choices: array_column(CreativeType::cases(), 'value'), message: 'invalid_choice'),
            ]),
            'quantity' => new Assert\Sequentially([
                new Assert\NotNull(message: 'required'),
                new Assert\Type('int', message: 'invalid'),
                new Assert\Range(min: self::QUANTITY_MIN, max: self::QUANTITY_MAX, notInRangeMessage: 'out_of_range'),
            ]),
            'description' => new Assert\Sequentially([
                new Assert\NotBlank(message: 'required', normalizer: 'trim'),
                new Assert\Type('string', message: 'invalid'),
                new Assert\Length(max: self::DESCRIPTION_MAX, maxMessage: 'too_long'),
            ]),
            'priority' => new Assert\Sequentially([
                new Assert\NotBlank(message: 'required'),
                new Assert\Choice(choices: array_column(Priority::cases(), 'value'), message: 'invalid_choice'),
            ]),
            'deadline' => new Assert\Sequentially([
                new Assert\NotBlank(message: 'required'),
                new Assert\Callback(static function (mixed $value, ExecutionContextInterface $context) use ($now): void {
                    $deadline = self::deadline($value);
                    if ($deadline === null) {
                        $context->buildViolation('invalid')->addViolation();
                    } elseif ($deadline <= $now) {
                        $context->buildViolation('must_be_future')->addViolation();
                    }
                }),
            ]),
            'references' => new Assert\Optional(new Assert\Sequentially([
                new Assert\Type('array', message: 'invalid'),
                new Assert\Count(max: self::REFERENCES_MAX, maxMessage: 'too_long'),
                new Assert\Callback(self::checkReferences(...)),
            ])),
        ]);
    }

    private static function checkGeo(mixed $value, ExecutionContextInterface $context): void
    {
        if ($value === null || $value === []) {
            $context->buildViolation('required')->addViolation();
        } elseif (!is_array($value) || !array_is_list($value)) {
            $context->buildViolation('invalid')->addViolation();
        } else {
            foreach ($value as $code) {
                if (!is_string($code) || !GeoCodes::isValid($code)) {
                    $context->buildViolation('invalid_choice')->addViolation();

                    return;
                }
            }
        }
    }

    private static function checkReferences(mixed $value, ExecutionContextInterface $context): void
    {
        if (!is_array($value)) {
            return;
        }

        if (!array_is_list($value)) {
            $context->buildViolation('invalid')->addViolation();

            return;
        }

        foreach ($value as $index => $reference) {
            if (!is_array($reference)) {
                $context->buildViolation('invalid')->atPath('[' . $index . ']')->addViolation();

                continue;
            }

            $path = static fn(string $key): string => '[' . $index . '][' . $key . ']';
            $violate = static function (string $code, string $key) use ($context, $path): void {
                $context->buildViolation($code)->atPath($path($key))->addViolation();
            };
            $tooLong = static function (string $value, string $key) use ($context, $path): void {
                // Through a real Length constraint, so that the violation carries params.max.
                $context->getValidator()->inContext($context)->atPath($path($key))
                    ->validate($value, new Assert\Length(max: self::URL_MAX, maxMessage: 'too_long'));
            };

            foreach (array_keys($reference) as $key) {
                if (!in_array($key, ['type', 'url', 'fileId'], true)) {
                    $violate('unknown_field', (string) $key);
                }
            }

            $type = $reference['type'] ?? null;
            if ($type === 'url') {
                self::checkUrl($reference['url'] ?? null, $violate, $tooLong);
            } elseif ($type === 'file') {
                $fileId = $reference['fileId'] ?? null;
                if ($fileId === null) {
                    $violate('required', 'fileId');
                } elseif (!is_int($fileId) || $fileId < 1) {
                    $violate('invalid', 'fileId');
                }
            } else {
                $violate($type === null ? 'required' : 'invalid_choice', 'type');
            }
        }
    }

    /**
     * @param callable(string, string): void $violate reports a code on a key of the reference
     * @param callable(string, string): void $tooLong reports too_long, with its limit, on a key of the reference
     */
    private static function checkUrl(mixed $url, callable $violate, callable $tooLong): void
    {
        if ($url === null || $url === '') {
            $violate('required', 'url');
        } elseif (!is_string($url)) {
            $violate('invalid', 'url');
        } elseif (mb_strlen($url, 'UTF-8') > self::URL_MAX) {
            $tooLong($url, 'url');
        } elseif (preg_match('/\p{Cc}/u', $url) === 1 || preg_match(self::URL_PATTERN, $url) !== 1) {
            $violate('invalid_url', 'url');
        }
    }

    /**
     * The instant of an ISO 8601 date-time that names its zone, in UTC to the second; null when the
     * value is not one (a date that does not exist, 2026-02-30, included).
     */
    private static function deadline(mixed $value): ?DateTimeImmutable
    {
        if (!is_string($value) || preg_match(self::DEADLINE_PATTERN, $value, $parts) !== 1) {
            return null;
        }

        try {
            $parsed = new DateTimeImmutable($value);
        } catch (Exception) {
            return null;
        }

        // PHP rolls 2026-02-30 over to March; what it prints back must be what was written.
        if ($parsed->format('Y-m-d\TH:i') !== $parts[1] . 'T' . $parts[2]) {
            return null;
        }

        $utc = $parsed->setTimezone(new DateTimeZone('UTC'));

        return $utc->setTime((int) $utc->format('H'), (int) $utc->format('i'), (int) $utc->format('s'));
    }

    /**
     * @param array<array-key, mixed> $codes
     * @return list<string>
     */
    private static function geo(array $codes): array
    {
        $geo = [];
        foreach ($codes as $code) {
            $geo[] = self::string($code);
        }

        return array_values(array_unique($geo));
    }

    /**
     * @param array<array-key, mixed> $references
     * @return list<NewReference>
     */
    private static function references(array $references): array
    {
        $parsed = [];
        foreach ($references as $reference) {
            if (!is_array($reference)) {
                throw new RuntimeException('A validated reference is not an object.');
            }

            $fileId = $reference['fileId'] ?? null;
            $parsed[] = ($reference['type'] ?? null) === 'file' && is_int($fileId)
                ? NewReference::file($fileId)
                : NewReference::url(self::string($reference['url'] ?? null));
        }

        return $parsed;
    }

    private static function string(mixed $value): string
    {
        return is_string($value) ? $value : throw new RuntimeException('A validated value is not a string.');
    }
}
