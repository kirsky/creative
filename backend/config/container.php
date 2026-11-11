<?php

declare(strict_types=1);

use App\Identity\Cli\RotateCredentialsCommand;
use App\Shared\Clock;
use App\Shared\Config;
use App\Shared\SystemClock;
use DI\ContainerBuilder;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;

use function DI\autowire;
use function DI\factory;

/*
 * The service container: PHP-DI with autowiring, so a class only needs an entry here when its
 * constructor takes something that cannot be guessed (an interface, a scalar).
 *
 * The file returns a factory taking $overrides, which replace definitions by name (tests pass a
 * fixed Clock, the test Config, a log sink, and so on). See App\createContainer().
 */
return static function (array $overrides = []): ContainerInterface {
    $builder = new ContainerBuilder();
    $builder->useAutowiring(true);

    $builder->addDefinitions([
        Config::class => static fn(): Config => Config::fromEnvironment(),

        Clock::class => autowire(SystemClock::class),

        ResponseFactoryInterface::class => autowire(ResponseFactory::class),

        // Demo commands take APP_ENV and the SAPI as plain constructor values (tests pass others);
        // DemoGuard decides what they may be.
        RotateCredentialsCommand::class => autowire()
            ->constructorParameter('appEnv', factory(static fn(Config $config): string => $config->get('APP_ENV')))
            ->constructorParameter('sapi', PHP_SAPI),

        // Logs go to stderr, which Docker collects; nothing is written to files in the image.
        LoggerInterface::class => static function (): Logger {
            $formatter = new LineFormatter(null, 'c', true, true);
            $formatter->includeStacktraces();

            $handler = new StreamHandler('php://stderr', Level::Info);
            $handler->setFormatter($formatter);

            return new Logger('creative', [$handler]);
        },
    ]);

    $builder->addDefinitions($overrides);

    return $builder->build();
};
