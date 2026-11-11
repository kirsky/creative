<?php

declare(strict_types=1);

namespace App\Rounds;

use App\AiUsage\UsageLinesParser;
use App\Shared\Http\ApiError;
use App\Shared\Validation\Validator;
use RuntimeException;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * Turns the JSON of POST /api/tasks/{id}/rounds into a NewRound, or says what is wrong with it as codes.
 *
 * `fileIds`: required (missing or empty), invalid (not a list, or an item that is not a positive
 * integer; the item is named fileIds.<index>), too_many (more than 25, params.max 25). `note`
 * (optional): invalid (not a string), too_long (more than 2000 characters, params.max 2000). Any other
 * field is unknown_field. A file id sent twice is invalid_choice on field `files`, the very answer a
 * file that cannot be attached gets (see FileStore::attach()).
 *
 * `aiUsage` (optional, zero lines when left out): the lines of AI credits spent on the round, checked
 * by UsageLinesParser. They are checked together with the files, so that one answer lists every
 * problem of the request.
 */
final class NewRoundParser
{
    public const int FILES_MAX = 25;

    public const int NOTE_MAX = 2000;

    public function __construct(
        private readonly Validator $validator,
        private readonly UsageLinesParser $usageLines,
    ) {}

    /**
     * @param array<array-key, mixed> $input decoded request data
     * @throws ApiError 422 validation_failed
     */
    public function parse(array $input): NewRound
    {
        $errors = [];
        $round = null;
        try {
            $round = $this->parseFiles($input);
        } catch (ApiError $e) {
            $errors = $e->fields;
        }

        $lines = array_key_exists('aiUsage', $input) ? $this->usageLines->check($input['aiUsage'], 'aiUsage', $errors) : [];

        if ($errors !== [] || $round === null) {
            throw new ApiError(422, 'validation_failed', $errors);
        }

        return new NewRound($round->note, $round->fileIds, $lines);
    }

    /**
     * @param array<array-key, mixed> $input
     * @throws ApiError 422 validation_failed
     */
    private function parseFiles(array $input): NewRound
    {
        $data = $this->validator->validate($input, new Assert\Collection([
            'fileIds' => new Assert\Required(new Assert\Sequentially([
                new Assert\NotBlank(message: 'required'),
                new Assert\Type('array', message: 'invalid'),
                new Assert\Count(max: self::FILES_MAX, maxMessage: 'too_many'),
                new Assert\All([
                    new Assert\Sequentially([
                        new Assert\NotNull(message: 'invalid'),
                        new Assert\Type('int', message: 'invalid'),
                        new Assert\Positive(message: 'invalid'),
                    ]),
                ]),
            ])),
            'note' => new Assert\Optional(new Assert\Sequentially([
                new Assert\Type('string', message: 'invalid'),
                new Assert\Length(max: self::NOTE_MAX, maxMessage: 'too_long'),
            ])),
            // Checked by UsageLinesParser.
            'aiUsage' => new Assert\Optional(),
        ]));

        $fileIds = $data['fileIds'] ?? null;
        if (!is_array($fileIds) || !array_is_list($fileIds)) {
            // A JSON object with 1..25 keys passes the rules above; only a list is a list of files.
            throw new ApiError(422, 'validation_failed', [['field' => 'fileIds', 'code' => 'invalid', 'params' => []]]);
        }

        $ids = [];
        foreach ($fileIds as $fileId) {
            $ids[] = is_int($fileId) ? $fileId : throw new RuntimeException('A validated file id is not an integer.');
        }

        if (count(array_unique($ids)) !== count($ids)) {
            throw new ApiError(422, 'validation_failed', [['field' => 'files', 'code' => 'invalid_choice', 'params' => []]]);
        }

        $note = $data['note'] ?? null;

        return new NewRound(is_string($note) && $note !== '' ? $note : null, $ids);
    }
}
