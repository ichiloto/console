<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Closure;
use RuntimeException;
use Throwable;

/** The Play process stays alive; its authenticated socket owns the detached game. */
final class TerminalSessionLauncher
{
    /** Optional existing game diagnostic file to mirror to the editor's Play log. */
    public const string DIAGNOSTIC_LOG_ENVIRONMENT = 'ICHILOTO_TERMINAL_DIAGNOSTIC_LOG';
    private ?string $failureReason = null;
    private bool $cancelled = false;
    private Closure $outputWriter;
    private Closure $cancellationRequested;

    /**
     * @param callable(string): void|null $outputWriter Defaults to Play's stderr.
     * @param callable(): bool|null $cancellationRequested A caller-owned cancellation seam.
     */
    public function __construct(
        private ?TerminalSessionWindow $window = null,
        private ?string $scratchRoot = null,
        private float $readyTimeout = 10.0,
        ?callable $outputWriter = null,
        ?callable $cancellationRequested = null,
    ) {
        if ($readyTimeout <= 0 || $readyTimeout > 60) {
            throw new RuntimeException('Terminal ready timeout must be between zero and sixty seconds.');
        }
        $this->window ??= new TerminalSessionWindow();
        $this->scratchRoot ??= sys_get_temp_dir();
        $this->outputWriter = $outputWriter !== null ? Closure::fromCallable($outputWriter)
            : static function (string $text): void { fwrite(STDERR, $text); };
        $this->cancellationRequested = $cancellationRequested !== null ? Closure::fromCallable($cancellationRequested) : static fn (): bool => false;
    }

    public function cancel(): void
    {
        $this->cancelled = true;
    }

    public function getFailureReason(): ?string
    {
        return $this->failureReason;
    }

    /** @param list<string> $gameArguments
     * @param array<string, string> $environment
     * @return int|null Child exit status, 130/143 on cancellation, null on refusal/start failure.
     */
    public function launch(string $workingDirectory, array $gameArguments, array $environment): ?int
    {
        $this->failureReason = null;
        $this->cancelled = false;
        $server = null;
        $channel = null;
        $opener = null;
        $scratch = null;
        $diagnostic = null;
        $diagnosticPath = $environment[self::DIAGNOSTIC_LOG_ENVIRONMENT] ?? null;
        $result = null;
        $nonce = bin2hex(random_bytes(32));
        $signal = 0;
        $handlers = [];
        $asyncSignals = null;
        try {
            $this->validateLaunch($workingDirectory, $gameArguments, $environment);
            $reason = $this->window->describeUnavailable();
            if ($reason !== null) {
                throw new RuntimeException($reason);
            }
            $owner = new TerminalSessionOwner();
            $nextOwnerCheck = 0.0;
            $server = @stream_socket_server('tcp://127.0.0.1:0', $error, $message);
            if (! is_resource($server)) {
                throw new RuntimeException('Could not create the local Terminal lifetime rendezvous: ' . $message);
            }
            stream_set_blocking($server, false);
            $address = stream_socket_get_name($server, false);
            $deadline = microtime(true) + $this->readyTimeout;
            $scratch = new TerminalSessionScratch($this->scratchRoot, $nonce);
            if (function_exists('pcntl_signal')) {
                $asyncSignals = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM, SIGHUP] as $number) {
                    $handlers[$number] = pcntl_signal_get_handler($number);
                    pcntl_signal($number, function (int $received) use (&$signal): void {
                        $signal = $received;
                        $this->cancelled = true;
                    });
                }
            }
            if (isset($environment[self::DIAGNOSTIC_LOG_ENVIRONMENT])) {
                $diagnostic = @fopen($environment[self::DIAGNOSTIC_LOG_ENVIRONMENT], 'rb');
                if (is_resource($diagnostic)) {
                    fseek($diagnostic, 0, SEEK_END);
                }
            }
            $opener = $this->window->open([
                PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'register_argc_argv=1', __DIR__ . '/TerminalSessionWorker.php',
                (string) $address, $nonce, (string) $deadline,
            ], $nonce);
            $ready = false;
            $authenticated = false;
            $candidateDeadline = $deadline;
            while (true) {
                $ownerDied = false;
                if (microtime(true) >= $nextOwnerCheck) {
                    $ownerDied = ! $owner->isAlive();
                    $nextOwnerCheck = microtime(true) + 0.5;
                }
                if ($this->cancelled || ($this->cancellationRequested)() || $ownerDied) {
                    $this->cancelled = true;
                    $result = 128 + ($signal ?: 15);
                    break;
                }
                if (! $ready && microtime(true) >= $deadline) {
                    throw new RuntimeException('Terminal window did not become ready within the bounded startup timeout.');
                }
                if ($channel === null) {
                    $stream = @stream_socket_accept($server, 0);
                    if (is_resource($stream)) {
                        $channel = new TerminalSessionChannel($stream);
                        $candidateDeadline = min($deadline, microtime(true) + 0.5);
                    }
                }
                if ($channel !== null) {
                    $packet = $channel->receiveMessage();
                    if (! $authenticated && $packet !== null) {
                        if (($packet['type'] ?? null) !== 'hello' || ! is_string($packet['nonce'] ?? null) || ! hash_equals($nonce, $packet['nonce'])) {
                            $channel->close();
                            $channel = null;
                            continue;
                        }
                        $authenticated = true;
                        fclose($server);
                        $server = null;
                        $channel->sendMessage([
                            'type' => 'launch', 'directory' => $workingDirectory, 'arguments' => $gameArguments,
                            'environment' => $environment, 'scratchRoot' => $this->scratchRoot,
                        ]);
                    } elseif ($authenticated && $packet !== null) {
                        switch ($packet['type'] ?? null) {
                            case 'ready':
                                $ready = true;
                                break;
                            case 'failed':
                                throw new RuntimeException((string) ($packet['reason'] ?? 'Terminal game could not start.'));
                            case 'complete':
                                if (! is_int($packet['exitCode'] ?? null)) {
                                    throw new RuntimeException('Terminal game returned an invalid exit status.');
                                }
                                $result = $packet['exitCode'];
                                break 2;
                        }
                    }
                    if (! $authenticated && (microtime(true) >= $candidateDeadline || $channel->isClosed())) {
                        $channel->close();
                        $channel = null;
                    } elseif ($authenticated && $channel->isClosed()) {
                        throw new RuntimeException('Owned Terminal session closed before reporting completion.');
                    }
                }
                if (! is_resource($diagnostic) && $diagnosticPath !== null) {
                    $diagnostic = @fopen($diagnosticPath, 'rb');
                }
                $this->forwardDiagnostics($diagnostic);
                usleep(20000);
            }
        } catch (Throwable $exception) {
            $this->failureReason = $exception->getMessage();
            ($this->outputWriter)('Terminal session: ' . $this->failureReason . "\n");
        } finally {
            // EOF is the cancellation token, including on SIGKILL of this Play process.
            $channel?->close();
            if (is_resource($server)) {
                fclose($server);
            }
            if (! is_resource($diagnostic) && $diagnosticPath !== null) {
                $diagnostic = @fopen($diagnosticPath, 'rb');
            }
            $this->forwardDiagnostics($diagnostic);
            if (is_resource($diagnostic)) {
                fclose($diagnostic);
            }
            if ($opener !== null) {
                // Owned windows close on every completion, including a game crash.
                $stopDeadline = microtime(true) + 2.0;
                while ($opener->isRunning() && microtime(true) < $stopDeadline) {
                    usleep(20000);
                }
                if ($opener->isRunning()) {
                    $opener->stop();
                } elseif (! $opener->isRunning()) {
                    $opener->close();
                }
                $this->window->closeOwnedWindow($nonce);
            }
            $scratch?->remove();
            foreach ($handlers as $number => $handler) {
                pcntl_signal($number, $handler);
            }
            if ($asyncSignals !== null) {
                pcntl_async_signals($asyncSignals);
            }
        }

        if ($result !== null && $result !== 0) {
            ($this->outputWriter)('Terminal game exited with status ' . $result . ".\n");
        }

        return $result;
    }

    /** @param list<string> $arguments
     * @param array<string, string> $environment
     */
    private function validateLaunch(string $directory, array $arguments, array $environment): void
    {
        if (! is_dir($directory) || realpath($directory) !== $directory) {
            throw new RuntimeException('Terminal game directory must be an existing resolved absolute path.');
        }
        if (! array_is_list($arguments) || $arguments === [] || $arguments[0] === '') {
            throw new RuntimeException('Terminal game requires a nonempty argument vector.');
        }
        foreach ($arguments as $argument) {
            if (! is_string($argument) || str_contains($argument, "\0")) {
                throw new RuntimeException('Terminal game arguments must be NUL-free strings.');
            }
        }
        foreach ($environment as $key => $value) {
            if (! is_string($key) || $key === '' || strpbrk($key, "=\0") !== false || ! is_string($value) || str_contains($value, "\0")) {
                throw new RuntimeException('Terminal game environment must contain valid string keys and values.');
            }
        }
    }

    /** @param resource|false|null $stream */
    private function forwardDiagnostics($stream): void
    {
        if (is_resource($stream)) {
            $text = fread($stream, 65536);
            if ($text !== false && $text !== '') {
                ($this->outputWriter)($text);
            }
        }
    }
}
