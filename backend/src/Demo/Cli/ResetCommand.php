<?php

declare(strict_types=1);

namespace App\Demo\Cli;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'demo:reset',
    description: 'Delete all tasks and uploaded files, then seed the demo again (CLI only, APP_ENV=demo)',
    help: <<<'HELP'
        Deletes every task with its history, rounds and AI usage and every uploaded file (rows and bytes),
        then seeds the demo scenario again.

        Never touches accounts, password hashes, sessions, login attempts or security events: a reset does
        not lock anyone out and does not hand out credentials.
        Runs only on the command line with APP_ENV=demo.
        HELP,
)]
final class ResetCommand extends DemoCommand
{
    protected function perform(OutputInterface $output): int
    {
        self::report($output, 'Reset: seeded', $this->seeder->reset());

        return Command::SUCCESS;
    }
}
