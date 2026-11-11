<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * Sends several requests at the same instant, each from a real operating system process with a
 * connection of its own (bin/request_process.php). No sleeps: the processes boot, sign in and wait on
 * a file lock that this class releases once all of them are ready.
 *
 * Nothing here can hang a test run: waiting for a process is bounded by a deadline, after which
 * every process is killed and the race fails; a process that exits with a non-zero status fails it too.
 */
final class RaceRunner
{
    /** How long, in seconds, all processes together may take to get ready and to answer. */
    public const int TIMEOUT = 60;

    /**
     * @param string $workDir a directory of the test, where the gate file is made
     * @param list<array{userId: int, method: string, path: string, body?: array<string, mixed>}> $requests
     * @return list<array{status: int, body: string}> one answer per request, in the order given
     * @throws RuntimeException when a process does not get ready or answer in time, exits with a
     *     failure or answers with something that is not a response
     */
    public static function run(string $workDir, array $requests, int $timeout = self::TIMEOUT): array
    {
        $gateFile = $workDir . '/gate';
        $gate = fopen($gateFile, 'w');
        if ($gate === false || !flock($gate, LOCK_EX)) {
            throw new RuntimeException('Cannot lock the gate.');
        }

        $script = (string) realpath(__DIR__ . '/bin/request_process.php');
        $deadline = microtime(true) + $timeout;
        /** @var list<RaceContender> $contenders */
        $contenders = [];

        try {
            foreach ($requests as $request) {
                $command = [PHP_BINARY, $script, (string) $request['userId'], $request['method'], $request['path'], $gateFile];
                if (isset($request['body'])) {
                    $command[] = json_encode($request['body'], JSON_THROW_ON_ERROR);
                }
                $contenders[] = RaceContender::start($command);
            }

            // Phase 1: every contender prints "ready" once it has booted and signed in.
            self::wait($contenders, $deadline, static fn(RaceContender $c): bool => str_contains($c->stdout, "\n"));
            foreach ($contenders as $contender) {
                if (trim($contender->stdout) !== 'ready') {
                    throw new RuntimeException('A contender did not get ready: ' . $contender->stdout . $contender->stderr);
                }
                $contender->stdout = '';
            }

            // All are blocked on the gate: let them go together.
            flock($gate, LOCK_UN);

            // Phase 2: each prints its answer and ends.
            self::wait($contenders, $deadline, null);

            $results = [];
            foreach ($contenders as $contender) {
                $code = $contender->finish();
                if ($code !== 0) {
                    throw new RuntimeException(sprintf('A contender exited with status %d. Output: %s Errors: %s', $code, $contender->stdout, $contender->stderr));
                }

                $decoded = json_decode(trim($contender->stdout), true);
                if (!is_array($decoded) || !is_int($decoded['status'] ?? null) || !is_string($decoded['body'] ?? null)) {
                    throw new RuntimeException(sprintf('A contender gave no answer. Output: %s Errors: %s', $contender->stdout, $contender->stderr));
                }
                $results[] = ['status' => $decoded['status'], 'body' => $decoded['body']];
            }

            return $results;
        } finally {
            flock($gate, LOCK_UN);
            fclose($gate);
            foreach ($contenders as $contender) {
                $contender->kill();
            }
        }
    }

    /**
     * Reads the output of every contender until each is done (end of output, or $enough says the
     * standard output so far is enough), or fails when $deadline passes.
     *
     * @param list<RaceContender> $contenders
     * @param (callable(RaceContender): bool)|null $enough
     */
    private static function wait(array $contenders, float $deadline, ?callable $enough): void
    {
        $waiting = $contenders;

        while ($waiting !== []) {
            $remaining = $deadline - microtime(true);
            if ($remaining <= 0) {
                $output = array_map(static fn(RaceContender $c): string => $c->stdout . $c->stderr, $contenders);
                throw new RuntimeException('The contender processes did not finish in time. Output so far: ' . json_encode($output, JSON_PARTIAL_OUTPUT_ON_ERROR));
            }

            $read = [];
            foreach ($waiting as $contender) {
                array_push($read, ...$contender->streams());
            }
            $write = $except = null;
            $seconds = (int) $remaining;
            if (stream_select($read, $write, $except, $seconds, (int) (($remaining - $seconds) * 1_000_000)) === false) {
                throw new RuntimeException('Cannot wait for the contender processes.');
            }

            foreach ($waiting as $position => $contender) {
                $contender->drain();
                if ($contender->atEnd() || ($enough !== null && $enough($contender))) {
                    unset($waiting[$position]);
                }
            }
        }
    }
}
