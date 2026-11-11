<?php

declare(strict_types=1);

namespace App\Tasks\Http;

use App\Shared\Http\JsonResponder;
use App\Tasks\CreativeType;
use App\Tasks\Geo\GeoCodes;
use App\Tasks\Priority;
use Psr\Http\Message\ResponseInterface;

/**
 * GET /api/options: the codes the brief form and the board filters offer. Codes only; the frontend
 * translates them. Open to every signed-in role.
 */
final class OptionsAction
{
    public function __construct(private readonly JsonResponder $json) {}

    public function __invoke(): ResponseInterface
    {
        return $this->json->respond([
            'creativeTypes' => array_column(CreativeType::cases(), 'value'),
            'priorities' => array_column(Priority::cases(), 'value'),
            'regions' => GeoCodes::regions(),
            'countries' => GeoCodes::countries(),
        ]);
    }
}
