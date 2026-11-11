<?php

declare(strict_types=1);

namespace App\Demo\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'demo:seed',
    description: 'Create the demo accounts that are missing and the 16 demo tasks (CLI only, APP_ENV=demo)',
    help: <<<'HELP'
        Creates the six demo accounts that do not exist yet (without a password: run
        demo:credentials:rotate to get passwords) and the demo tasks, with history, files and AI usage.

        Refuses, changing nothing, when any task exists already: use demo:reset to replace the tasks.
        Runs only on the command line with APP_ENV=demo.
        HELP,
)]
final class SeedCommand extends DemoCommand
{
    protected function perform(OutputInterface $output): int
    {
        self::report($output, 'Seeded', $this->seeder->seed());

        return Command::SUCCESS;
    }
}
