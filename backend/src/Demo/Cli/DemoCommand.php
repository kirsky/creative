<?php

declare(strict_types=1);

namespace App\Demo\Cli;

use App\Demo\AlreadySeededException;
use App\Demo\DemoSeeder;
use App\Demo\SeedSummary;
use App\Shared\DemoGuard;
use RuntimeException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * What the three demo data commands share: they run only on the command line with APP_ENV=demo
 * (DemoGuard, before anything else, so a refused run touches nothing), they say what went wrong on
 * stderr in one line and exit non-zero, and they print counts only.
 */
abstract class DemoCommand extends Command
{
    /**
     * For what goes to stderr. RAW: no formatting. QUIET: shown with --quiet, hidden only by --silent.
     */
    private const int MESSAGE = OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET;

    public function __construct(
        protected readonly DemoSeeder $seeder,
        private readonly string $appEnv,
        private readonly string $sapi,
    ) {
        parent::__construct();
    }

    /**
     * The work of the command, after the guard. May throw; what is thrown is reported by execute().
     */
    abstract protected function perform(OutputInterface $output): int;

    final protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            DemoGuard::assertDemoCli($this->appEnv, $this->sapi);
        } catch (RuntimeException $refusal) {
            $stderr->writeln('error: ' . $refusal->getMessage() . ' Nothing was changed.', self::MESSAGE);

            return Command::FAILURE;
        }

        try {
            return $this->perform($output);
        } catch (AlreadySeededException $refusal) {
            $stderr->writeln('error: ' . $refusal->getMessage() . '. Nothing was changed.', self::MESSAGE);
        } catch (Throwable $failure) {
            $stderr->writeln(sprintf('error: failed (%s: %s).', $failure::class, $failure->getMessage()), self::MESSAGE);
        }

        return Command::FAILURE;
    }

    protected static function report(OutputInterface $output, string $verb, SeedSummary $summary): void
    {
        $output->writeln(
            sprintf('%s %d tasks, %d files; %d accounts created (existing accounts are kept as they are).', $verb, $summary->tasks, $summary->files, $summary->accountsCreated),
            OutputInterface::OUTPUT_RAW,
        );
    }
}
