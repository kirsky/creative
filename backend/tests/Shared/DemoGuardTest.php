<?php

declare(strict_types=1);

namespace App\Tests\Shared;

use App\Shared\DemoGuard;
use PHPUnit\Framework\Attributes\DataProvider;
use PHPUnit\Framework\TestCase;
use RuntimeException;

final class DemoGuardTest extends TestCase
{
    public function test_demo_on_the_command_line_passes(): void
    {
        DemoGuard::assertDemoCli('demo', 'cli');

        $this->expectNotToPerformAssertions();
    }

    #[DataProvider('everythingElse')]
    public function test_anything_else_is_refused(string $appEnv, string $sapi): void
    {
        $this->expectException(RuntimeException::class);

        DemoGuard::assertDemoCli($appEnv, $sapi);
    }

    /**
     * @return array<string, array{string, string}>
     */
    public static function everythingElse(): array
    {
        return [
            'production on the command line' => ['production', 'cli'],
            'test on the command line' => ['test', 'cli'],
            'no environment' => ['', 'cli'],
            'spelled differently' => ['Demo', 'cli'],
            'with a trailing newline' => ["demo\n", 'cli'],
            'demo under php-fpm' => ['demo', 'fpm-fcgi'],
            'demo under the built-in server' => ['demo', 'cli-server'],
            'demo under phpdbg' => ['demo', 'phpdbg'],
            'no SAPI' => ['demo', ''],
            'neither' => ['production', 'fpm-fcgi'],
        ];
    }
}
