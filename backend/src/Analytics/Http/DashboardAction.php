<?php

declare(strict_types=1);

namespace App\Analytics\Http;

use App\AiUsage\Period;
use App\Analytics\DashboardQuery;
use App\Identity\AuthMiddleware;
use App\Shared\Clock;
use App\Shared\Http\ApiError;
use App\Shared\Http\JsonResponder;
use App\Shared\Validation\Validator;
use App\Tasks\Action;
use App\Tasks\TaskPolicy;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use Symfony\Component\Validator\Constraints as Assert;

/**
 * GET /api/analytics/dashboard?period=7d|30d|all (default all): the manager dashboard. Any other
 * role is 403 forbidden (checked first, so that it does not depend on the query); any other period
 * is 422 validation_failed (period: invalid_choice).
 */
final class DashboardAction
{
    /** The periods the dashboard offers: the query value and its length in days (null: all time). */
    private const array PERIODS = ['7d' => 7, '30d' => 30, 'all' => null];

    public function __construct(
        private readonly DashboardQuery $query,
        private readonly TaskPolicy $policy,
        private readonly Validator $validator,
        private readonly Clock $clock,
        private readonly JsonResponder $json,
    ) {}

    public function __invoke(ServerRequestInterface $request): ResponseInterface
    {
        $actor = AuthMiddleware::actor($request);
        if (!$this->policy->can($actor, Action::ViewDashboard)) {
            throw ApiError::forbidden();
        }

        $data = $this->validator->validate($request->getQueryParams(), new Assert\Collection(
            fields: ['period' => new Assert\Optional(new Assert\Choice(choices: array_map(strval(...), array_keys(self::PERIODS)), message: 'invalid_choice'))],
            allowExtraFields: true,
        ));

        $period = $data['period'] ?? 'all';
        $days = is_string($period) ? (self::PERIODS[$period] ?? null) : null;

        return $this->json->respond($this->query->dashboard(
            $actor,
            $days === null ? Period::all() : Period::lastDays($days, $this->clock),
        ));
    }
}
