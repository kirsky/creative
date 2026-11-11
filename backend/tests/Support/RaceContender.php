<?php

declare(strict_types=1);

namespace App\Tests\Support;

use RuntimeException;

/**
 * One process of a RaceRunner race and what it has printed so far.
 */
final class RaceContender
{
    public string $stdout = '';

    public string $stderr = '';

    private bool $closed = false;

    /**
     * @param resource $process
     * @param resource $out
     * @param resource $err
     */
    private function __construct(
        private $process,
        private $out,
        private $err,
    ) {}

    /**
     * @param list<string> $command
     */
    public static function start(array $command): self
    {
        $pipes = [];
        $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        if (!is_resource($process)) {
            throw new RuntimeException('Cannot start a contender process.');
        }

        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);

        return new self($process, $pipes[1], $pipes[2]);
    }

    /**
     * @return list<resource>
     */
    public function streams(): array
    {
        return [$this->out, $this->err];
    }

    /**
     * Takes what the process has printed since the last call.
     */
    public function drain(): void
    {
        $this->stdout .= (string) stream_get_contents($this->out);
        $this->stderr .= (string) stream_get_contents($this->err);
    }

    public function atEnd(): bool
    {
        return feof($this->out) && feof($this->err);
    }

    /**
     * Closes the process and returns its exit status.
     */
    public function finish(): int
    {
        $this->closed = true;
        fclose($this->out);
        fclose($this->err);

        return proc_close($this->process);
    }

    /**
     * Ends the process if it is still running (a failed or timed-out race).
     */
    public function kill(): void
    {
        if ($this->closed) {
            return;
        }

        $this->closed = true;
        proc_terminate($this->process, 9);
        fclose($this->out);
        fclose($this->err);
        proc_close($this->process);
    }
}
