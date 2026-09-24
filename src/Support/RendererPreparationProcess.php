<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;
use Throwable;
use Symfony\Component\Console\Output\OutputInterface;

/** Runs preparation tools without starting a game or invoking a command shell. */
final class RendererPreparationProcess
{
    private const float DEFAULT_TIMEOUT_SECONDS = 30;
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;
    private const int OUTPUT_LIMIT_BYTES = 65536;
    private const int READ_CHUNK_BYTES = 8192;
    private const int SELECT_WAIT_MICROSECONDS = 100000;
    private const int EXIT_POLL_MICROSECONDS = 10000;
    private const float TERMINATION_GRACE_SECONDS = 0.5;

    /** @param list<string> $arguments */
    public function run(array $arguments, string $directory, ?OutputInterface $output = null, float $timeoutSeconds = self::DEFAULT_TIMEOUT_SECONDS): string
    {
        if (! is_finite($timeoutSeconds) || $timeoutSeconds <= 0) { throw new RuntimeException('Preparation timeout must be positive and finite.'); }
        $pipes = [];
        $process = @proc_open($arguments, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes, $directory, null, ['bypass_shell' => true]);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not start the renderer preparation tool.');
        }
        fclose($pipes[0]);
        stream_set_blocking($pipes[1], false);
        stream_set_blocking($pipes[2], false);
        $captured = $errors = '';
        $deadline = hrtime(true) / self::NANOSECONDS_PER_SECOND + $timeoutSeconds;
        try {
            while (! feof($pipes[1]) || ! feof($pipes[2])) {
                if (hrtime(true) / self::NANOSECONDS_PER_SECOND >= $deadline) {
                    throw new RuntimeException('Renderer preparation timed out after ' . $timeoutSeconds . ' seconds.');
                }
                $read = array_values(array_filter([$pipes[1], $pipes[2]], static fn ($pipe): bool => ! feof($pipe)));
                $write = $except = null;
                if (@stream_select($read, $write, $except, 0, self::SELECT_WAIT_MICROSECONDS) === false) {
                    throw new RuntimeException('Could not read renderer preparation output.');
                }
                foreach ($read as $pipe) {
                    $chunk = fread($pipe, self::READ_CHUNK_BYTES);
                    if ($chunk === false) { throw new RuntimeException('Renderer preparation output could not be read.'); }
                    if ($pipe === $pipes[2]) { $errors = substr($errors . $chunk, -self::OUTPUT_LIMIT_BYTES); }
                    elseif ($output === null) {
                        $captured .= $chunk;
                        if (strlen($captured) > self::OUTPUT_LIMIT_BYTES) { throw new RuntimeException('Renderer preparation description exceeds the 64 KiB output limit.'); }
                    }
                    $output?->write($chunk, false, OutputInterface::OUTPUT_RAW);
                }
            }
            // A tool may close its pipes and then hang instead of exiting.
            do {
                $state = proc_get_status($process);
                if (! $state['running']) { break; }
                if (hrtime(true) / self::NANOSECONDS_PER_SECOND >= $deadline) {
                    throw new RuntimeException('Renderer preparation timed out after ' . $timeoutSeconds . ' seconds.');
                }
                usleep(self::EXIT_POLL_MICROSECONDS);
            } while (true);
        } catch (Throwable $error) {
            $this->terminate($process);
            throw $error;
        } finally {
            fclose($pipes[1]);
            fclose($pipes[2]);
            $status = proc_close($process);
        }
        if ($status === -1) { $status = $state['exitcode']; }
        if ($status !== 0) {
            throw new RuntimeException('Renderer preparation failed (exit ' . $status . '). ' . trim($errors));
        }
        return $captured;
    }

    /** @param resource $process */
    private function terminate($process): void
    {
        if (! proc_get_status($process)['running']) { return; }
        proc_terminate($process);
        $deadline = hrtime(true) / self::NANOSECONDS_PER_SECOND + self::TERMINATION_GRACE_SECONDS;
        while (proc_get_status($process)['running']) {
            if (hrtime(true) / self::NANOSECONDS_PER_SECOND >= $deadline) {
                proc_terminate($process, 9);
                return;
            }
            usleep(self::EXIT_POLL_MICROSECONDS);
        }
    }
}
