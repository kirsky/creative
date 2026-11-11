<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\Http\ApiError;
use App\Shared\Validation\Validator;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Validator\Constraints as Assert;

final class ValidatorTest extends TestCase
{
    public function test_violation_becomes_field_code_params(): void
    {
        $error = $this->failure(
            ['offer' => ''],
            new Assert\Collection(['offer' => new Assert\NotBlank(message: 'required')]),
        );

        self::assertSame(422, $error->status);
        self::assertSame('validation_failed', $error->code);
        self::assertSame([['field' => 'offer', 'code' => 'required', 'params' => []]], $error->fields);

        $error = $this->failure(
            ['name' => 'a'],
            new Assert\Collection(['name' => new Assert\Length(min: 2, minMessage: 'too_short')]),
        );

        self::assertSame([['field' => 'name', 'code' => 'too_short', 'params' => ['min' => 2]]], $error->fields);
    }

    public function test_valid_data_is_returned_unchanged(): void
    {
        $data = ['offer' => '🎰 <script>alert(1)</script> Лото', 'count' => 3];
        $rules = new Assert\Collection([
            'offer' => [new Assert\NotBlank(message: 'required'), new Assert\Length(max: 200, maxMessage: 'too_long')],
            'count' => new Assert\Range(min: 1, max: 5, notInRangeMessage: 'out_of_range'),
        ]);

        self::assertSame($data, (new Validator())->validate($data, $rules));
    }

    public function test_too_long_reports_the_maximum(): void
    {
        $error = $this->failure(
            ['name' => 'abcdef'],
            new Assert\Collection(['name' => new Assert\Length(max: 3, maxMessage: 'too_long')]),
        );

        self::assertSame([['field' => 'name', 'code' => 'too_long', 'params' => ['max' => 3]]], $error->fields);
    }

    public function test_length_counts_characters_not_bytes(): void
    {
        $rules = new Assert\Collection(['name' => new Assert\Length(min: 2, max: 4, minMessage: 'too_short', maxMessage: 'too_long')]);
        $validator = new Validator();

        // 4 Cyrillic letters are 8 bytes, 2 emoji are 8 bytes; both are within 2..4 characters.
        self::assertSame(['name' => 'Лото'], $validator->validate(['name' => 'Лото'], $rules));
        self::assertSame(['name' => '🎰🎰'], $validator->validate(['name' => '🎰🎰'], $rules));
    }

    public function test_range_reports_both_bounds(): void
    {
        $error = $this->failure(
            ['credits' => 9],
            new Assert\Collection(['credits' => new Assert\Range(min: 1, max: 5, notInRangeMessage: 'out_of_range')]),
        );

        self::assertSame(
            [['field' => 'credits', 'code' => 'out_of_range', 'params' => ['min' => 1, 'max' => 5]]],
            $error->fields,
        );
    }

    public function test_choice_violation_uses_its_message_as_code(): void
    {
        $error = $this->failure(
            ['priority' => 'urgent'],
            new Assert\Collection(['priority' => new Assert\Choice(choices: ['low', 'high'], message: 'invalid_choice')]),
        );

        self::assertSame([['field' => 'priority', 'code' => 'invalid_choice', 'params' => []]], $error->fields);
    }

    public function test_missing_field_is_required(): void
    {
        $error = $this->failure(
            [],
            new Assert\Collection(['offer' => new Assert\NotBlank(message: 'required')]),
        );

        self::assertSame([['field' => 'offer', 'code' => 'required', 'params' => []]], $error->fields);
    }

    public function test_unexpected_field_is_reported_not_silently_accepted(): void
    {
        $error = $this->failure(
            ['offer' => 'x', 'role' => 'manager'],
            new Assert\Collection(['offer' => new Assert\NotBlank(message: 'required')]),
        );

        self::assertSame([['field' => 'role', 'code' => 'unknown_field', 'params' => []]], $error->fields);
    }

    public function test_a_constraint_without_a_code_never_leaks_english_prose(): void
    {
        $error = $this->failure(
            ['offer' => ''],
            new Assert\Collection(['offer' => new Assert\NotBlank()]),
        );

        self::assertSame([['field' => 'offer', 'code' => 'invalid', 'params' => []]], $error->fields);
    }

    public function test_nested_paths_are_dotted(): void
    {
        $error = $this->failure(
            ['links' => ['https://ok.example', '']],
            new Assert\Collection([
                'links' => new Assert\All([new Assert\NotBlank(message: 'required')]),
            ]),
        );

        self::assertSame([['field' => 'links.1', 'code' => 'required', 'params' => []]], $error->fields);
    }

    public function test_every_violation_is_reported_in_one_response(): void
    {
        $error = $this->failure(
            ['offer' => '', 'name' => 'a'],
            new Assert\Collection([
                'offer' => new Assert\NotBlank(message: 'required'),
                'name' => new Assert\Length(min: 2, minMessage: 'too_short'),
            ]),
        );

        self::assertSame(['offer', 'name'], array_column($error->fields, 'field'));
    }

    /**
     * @param array<string, mixed> $data
     */
    private function failure(array $data, Assert\Collection $rules): ApiError
    {
        try {
            (new Validator())->validate($data, $rules);
        } catch (ApiError $error) {
            return $error;
        }

        self::fail('Expected validation to fail.');
    }
}
