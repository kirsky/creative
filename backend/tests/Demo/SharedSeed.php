<?php

declare(strict_types=1);

namespace App\Tests\Demo;

use Attribute;

/**
 * Marks a test class whose tests only read the seeded demo. They share one seed (see
 * DemoTestCase::sharedSeed()) instead of paying for a seed each; they may start sessions (to call the
 * API as a user) but must not change anything else.
 */
#[Attribute(Attribute::TARGET_CLASS)]
final class SharedSeed {}
