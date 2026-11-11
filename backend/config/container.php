<?php

declare(strict_types=1);

use App\Demo\Cli\ResetCommand;
use App\Demo\Cli\SeedCommand;
use App\Demo\Cli\SeedIfEmptyCommand;
use App\Files\AttachedTaskResolver;
use App\Identity\Cli\RotateCredentialsCommand;
use App\Rounds\RoundQuery;
use App\Shared\Clock;
use App\Shared\Config;
use App\Shared\SystemClock;
use App\Tasks\AttachedTasks;
use App\Tasks\RoundReader;
use DI\ContainerBuilder;
use Monolog\Formatter\LineFormatter;
use Monolog\Handler\StreamHandler;
use Monolog\Level;
use Monolog\Logger;
use Psr\Container\ContainerInterface;
use Psr\Http\Message\ResponseFactoryInterface;
use Psr\Http\Message\StreamFactoryInterface;
use Psr\Log\LoggerInterface;
use Slim\Psr7\Factory\ResponseFactory;
use Slim\Psr7\Factory\StreamFactory;

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
        StreamFactoryInterface::class => autowire(StreamFactory::class),

        // Which tasks a file is attached to: FileAccess lets whoever may view them read the file.
        AttachedTaskResolver::class => autowire(AttachedTasks::class),

        // Where a card's round count, AI credits and "returning" flag, and the rounds of the task page, come from.
        RoundReader::class => autowire(RoundQuery::class),

        // Demo commands take APP_ENV and the SAPI as plain constructor values (tests pass others);
        // DemoGuard decides what they may be.
        RotateCredentialsCommand::class => autowire()
            ->constructorParameter('appEnv', factory(static fn(Config $config): string => $config->get('APP_ENV')))
            ->constructorParameter('sapi', PHP_SAPI),

        // The same for the three commands that fill and empty the demo.
        SeedCommand::class => autowire()
            ->constructorParameter('appEnv', factory(static fn(Config $config): string => $config->get('APP_ENV')))
            ->constructorParameter('sapi', PHP_SAPI),
        ResetCommand::class => autowire()
            ->constructorParameter('appEnv', factory(static fn(Config $config): string => $config->get('APP_ENV')))
            ->constructorParameter('sapi', PHP_SAPI),
        SeedIfEmptyCommand::class => autowire()
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
