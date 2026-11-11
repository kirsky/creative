<?php

declare(strict_types=1);

namespace App\Demo\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'demo:seed-if-empty',
    description: 'Seed the demo only when no task exists (CLI only, APP_ENV=demo); used when the container starts',
    help: <<<'HELP'
        Does nothing, successfully, when any task exists. Otherwise behaves like demo:seed.

        The php container runs it on every start, so the first start of a fresh database is seeded and a
        restart never replaces what an evaluator has been working on.
        Runs only on the command line with APP_ENV=demo.
        HELP,
)]
final class SeedIfEmptyCommand extends DemoCommand
{
    protected function perform(OutputInterface $output): int
    {
        if ($this->seeder->hasTasks()) {
            $output->writeln('Tasks exist already; nothing to seed.', OutputInterface::OUTPUT_RAW);

            return Command::SUCCESS;
        }

        self::report($output, 'Seeded', $this->seeder->seed());

        return Command::SUCCESS;
    }
}
