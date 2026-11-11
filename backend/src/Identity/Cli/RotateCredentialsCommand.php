<?php

declare(strict_types=1);

namespace App\Identity\Cli;

use App\Identity\PasswordHasher;
use App\Identity\SecurityEventLog;
use App\Identity\SessionService;
use App\Identity\UserRepository;
use App\Shared\Db\Database;
use App\Shared\DemoGuard;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

/**
 * demo:credentials:rotate: gives every account a new random password and ends all sessions.
 *
 * This is the only source of the demo's passwords: the seed creates accounts without one, so they
 * cannot sign in until this runs. The passwords exist in this process and on stdout, nowhere else:
 * not in the database (only argon2id hashes), the security event, a log, an environment variable,
 * a file or an exception message. Nothing is printed until the database transaction that replaced
 * the hashes, revoked the sessions and recorded the event has committed; if anything fails, nothing
 * is printed and nothing has changed.
 *
 * Output contract, read by the Playwright setup: stdout is one line per account, in account id order,
 * exactly `username<TAB>password` (no header, no colours, even with --ansi, --quiet or --silent). Everything a
 * person should read goes to stderr.
 */
#[AsCommand(
    name: 'demo:credentials:rotate',
    description: 'Generate new passwords for every demo account and end all sessions (CLI only, APP_ENV=demo)',
    help: <<<'HELP'
        Replaces the password of every account with a new random one and ends all sessions.

        stdout: one line per account, in account id order: username, a TAB, the new password.
        stderr: warnings and errors. The passwords are shown once and cannot be recovered; run the
        command again to get new ones.

        Runs only on the command line with APP_ENV=demo. Fails, changing nothing, when there are no
        accounts: run the demo seed first.
        HELP,
)]
final class RotateCredentialsCommand extends Command
{
    /** Without the look-alikes 0/O, 1/l/I. 55 characters: 20 of them are about 115 bits. */
    private const ALPHABET = 'abcdefghjkmnpqrstuvwxyzABCDEFGHJKLMNPQRSTUVWXYZ23456789';

    private const PASSWORD_LENGTH = 20;

    /**
     * For what goes to stderr. RAW: no formatting, so a username that looks like a style tag is printed
     * as it is. QUIET: shown with --quiet (Symfony's "only errors"), hidden only by --silent.
     */
    private const MESSAGE = OutputInterface::OUTPUT_RAW | OutputInterface::VERBOSITY_QUIET;

    public function __construct(
        private readonly UserRepository $users,
        private readonly PasswordHasher $hasher,
        private readonly SessionService $sessions,
        private readonly SecurityEventLog $events,
        private readonly Database $database,
        private readonly string $appEnv,
        private readonly string $sapi,
    ) {
        parent::__construct();
    }

    protected function execute(InputInterface $input, OutputInterface $output): int
    {
        $stderr = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;

        try {
            DemoGuard::assertDemoCli($this->appEnv, $this->sapi);
        } catch (RuntimeException $refusal) {
            $stderr->writeln('error: ' . $refusal->getMessage() . ' Nothing was changed.', self::MESSAGE);

            return Command::FAILURE;
        }

        try {
            $credentials = $this->database->transaction($this->rotate(...));
        } catch (Throwable $failure) {
            // Rolled back by transaction(). The message cannot hold a password: none is ever put into
            // an exception, and the hasher takes it as a sensitive parameter.
            $stderr->writeln(sprintf('error: credential rotation failed (%s: %s); nothing was changed.', $failure::class, $failure->getMessage()), self::MESSAGE);

            return Command::FAILURE;
        }

        if ($credentials === []) {
            $stderr->writeln('error: no accounts exist; run the demo seed first. Nothing was changed.', self::MESSAGE);

            return Command::FAILURE;
        }

        // Committed: only now do the passwords leave this process.
        self::printCredentials($output, $credentials);

        $stderr->writeln(sprintf(
            'Rotated the passwords of %d accounts and ended all their sessions. The passwords are shown once and cannot be recovered: store them now.',
            count($credentials),
        ), self::MESSAGE);

        return Command::SUCCESS;
    }

    /**
     * The work of one transaction. Returns what to print, which is empty only when there are no accounts.
     *
     * @return list<array{string, string}> username and new password, in account id order
     */
    private function rotate(): array
    {
        $accounts = $this->users->all();
        $credentials = [];

        foreach ($accounts as $account) {
            // The output is tab separated lines: a username that could split one cannot be printed.
            if (preg_match('/[\x00-\x1f\x7f]/', $account->username) === 1) {
                throw new RuntimeException(sprintf('account %d has a control character in its username and cannot be printed as a line', $account->id));
            }

            // Each account has its own password, drawn on its own.
            $password = self::generatePassword();

            $this->users->setPasswordHash($account->id, $this->hasher->hash($password));
            $this->sessions->revokeAllFor($account->id);

            $credentials[] = [$account->username, $password];
        }

        if ($credentials !== []) {
            // Counts only: no username, no password, nothing that could sign in.
            $this->events->record(SecurityEventLog::CREDENTIALS_ROTATED, null, ['accounts' => count($credentials)]);
        }

        return $credentials;
    }

    /**
     * One `username<TAB>password` line each, raw (no formatting, no colours), whatever the verbosity.
     * Output::write() drops anything below the output's verbosity and nothing is below --quiet and
     * --silent; but these lines are the command's result, and the passwords are already replaced, so
     * a run that hid them would lock everyone out.
     *
     * @param list<array{string, string}> $credentials
     */
    private static function printCredentials(OutputInterface $output, array $credentials): void
    {
        $verbosity = $output->getVerbosity();
        $output->setVerbosity(OutputInterface::VERBOSITY_NORMAL);

        try {
            foreach ($credentials as [$username, $password]) {
                $output->writeln($username . "\t" . $password, OutputInterface::OUTPUT_RAW);
            }
        } finally {
            $output->setVerbosity($verbosity);
        }
    }

    private static function generatePassword(): string
    {
        $password = '';
        $last = strlen(self::ALPHABET) - 1;

        for ($i = 0; $i < self::PASSWORD_LENGTH; ++$i) {
            $password .= self::ALPHABET[random_int(0, $last)];
        }

        return $password;
    }
}
