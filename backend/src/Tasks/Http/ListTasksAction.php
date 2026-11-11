<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\Identity\AuthMiddleware;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use App\Tasks\BoardFilter;
use App\Tasks\CreativeType;
use App\Tasks\NewTaskParser;
use App\Tasks\Priority;
use App\Tasks\TaskQuery;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * GET /api/tasks: the board, narrowed by the optional query parameters priority, creativeType,
 * designerId, q and includeCancelled (1). Parameters that are empty or unknown are ignored; a known
 * one with a value that cannot be is 422 validation_failed. Answers {tasks: TaskCard[]} for what the
 * viewer may see, whatever they ask for.
 */
final class ListTasksAction
{
    public const int SEARCH_MAX = NewTaskParser::OFFER_MAX;

    public function __construct(
        private readonly TaskQuery $query,
        private readonly Validator $validator,
        private readonly JsonResponder $json,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $params = array_filter($request->getQueryParams(), static fn(mixed $value): bool => $value !== '');

        $data = $this->validator->validate($params, new Assert\Collection(
            fields: [
                'priority' => new Assert\Optional(new Assert\Choice(choices: array_column(Priority::cases(), 'value'), message: 'invalid_choice')),
                'creativeType' => new Assert\Optional(new Assert\Choice(choices: array_column(CreativeType::cases(), 'value'), message: 'invalid_choice')),
                'designerId' => new Assert\Optional(new Assert\Sequentially([
                    new Assert\Type('string', message: 'invalid'),
                    new Assert\Regex(pattern: '/^[0-9]{1,10}$/', message: 'invalid'),
                ])),
                'includeCancelled' => new Assert\Optional(new Assert\Choice(choices: ['0', '1'], message: 'invalid_choice')),
                'q' => new Assert\Optional(new Assert\Sequentially([
                    new Assert\Type('string', message: 'invalid'),
                    new Assert\Length(max: self::SEARCH_MAX, maxMessage: 'too_long'),
                ])),
            ],
            allowExtraFields: true,
        ));

        $designerId = $data['designerId'] ?? null;
        $q = $data['q'] ?? null;
        $priority = $data['priority'] ?? null;
        $creativeType = $data['creativeType'] ?? null;

        $cards = $this->query->board(AuthMiddleware::actor($request), new BoardFilter(
            is_string($priority) ? $priority : null,
            is_string($creativeType) ? $creativeType : null,
            is_string($designerId) ? (int) $designerId : null,
            is_string($q) ? $q : null,
            ($data['includeCancelled'] ?? null) === '1',
        ));

        return $this->json->respond(['tasks' => $cards]);
    }
}
