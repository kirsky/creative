<?php

declare(strict_types=1);

namespace App\Shared;

use RuntimeException;

/**
 * The gate in front of every command that changes the demo for good: rotating the demo credentials,
 * seeding, resetting. Such a command runs only with APP_ENV=demo and only on the command line, so a
 * web request, a test or a production deployment can never reach it, whatever else is wired up.
 */
final class DemoGuard
{
    /**
     * Callers pass Config's APP_ENV and PHP_SAPI. The comparison is exact: "Demo" and "demo\n" are not demo.
     *
     * @throws RuntimeException unless $appEnv is "demo" and $sapi is "cli"
     */
    public static function assertDemoCli(string $appEnv, string $sapi): void
    {
        if ($appEnv === 'demo' && $sapi === 'cli') {
            return;
        }

        throw new RuntimeException(sprintf(
            'refusing to run: this needs APP_ENV=demo on the command line, but APP_ENV is "%s" and the SAPI is "%s".',
            addcslashes($appEnv, "\0..\37\177..\377"),
            addcslashes($sapi, "\0..\37\177..\377"),
        ));
    }
}
