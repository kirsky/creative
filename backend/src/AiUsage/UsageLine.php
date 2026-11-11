<?php

declare(strict_types=1);

namespace App\AiUsage;

/**
 * One reported line of AI usage, validated: the provider's name (trimmed, spaces collapsed, 1 to 50
 * characters; not yet matched against the list of providers) and the credits as a decimal string with
 * exactly two decimals ("40.00"), never a float.
 */
final readonly class UsageLine
{
    public function __construct(
        public string $provider,
        public string $credits,
    ) {}
}
