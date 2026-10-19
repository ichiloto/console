<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Closure;
use RuntimeException;

/** Platform window creation is injectable; commands never contain game data. */
final class TerminalSessionWindow
{
    private Closure $commandRunner;
    private Closure $findExecutable;

    /**
     * @param callable(list<string>, array<int, mixed>, array<string, mixed>): (resource|false)|null $commandRunner
     * @param callable(string): ?string|null $findExecutable
     */
    public function __construct(
        private string $platform = PHP_OS_FAMILY,
        ?callable $commandRunner = null,
        ?callable $findExecutable = null,
    ) {
        $this->commandRunner = $commandRunner !== null ? Closure::fromCallable($commandRunner)
            : static fn (array $arguments, array $descriptors, array $options) => TerminalSessionProcess::start($arguments, $descriptors, options: $options);
        $this->findExecutable = $findExecutable !== null ? Closure::fromCallable($findExecutable) : self::findExecutable(...);
    }

    public function describeUnavailable(): ?string
    {
        if (! in_array($this->platform, ['Darwin', 'Linux', 'Windows'], true)) {
            return 'Standalone Terminal sessions are unsupported on ' . $this->platform . '.';
        }
        if ($this->platform !== 'Windows' && (! function_exists('pcntl_signal') || ! function_exists('posix_kill'))) {
            return 'Standalone Terminal sessions require the PHP pcntl and posix extensions on this platform.';
        }
        $executable = match ($this->platform) {
            'Darwin' => 'osascript',
            'Linux' => 'xterm',
            default => PHP_BINARY,
        };
        if (($this->findExecutable)($executable) === null) {
            return 'Standalone Terminal session requires an available ' . $executable . ' executable.';
        }
        if ($this->platform === 'Windows') {
            foreach ([TerminalSessionProcess::getWindowsPowerShell(), (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\taskkill.exe'] as $helper) {
                if (($this->findExecutable)($helper) === null) {
                    return 'Owned Windows Terminal sessions require the local PowerShell and taskkill process-lifetime helpers.';
                }
            }
        }
        if ($this->platform === 'Linux' && (getenv('DISPLAY') ?: '') === '') {
            return 'xterm requires an X11 display (including XWayland).';
        }

        return null;
    }

    /** @param list<string> $workerArguments */
    public function open(array $workerArguments, string $nonce): TerminalSessionProcess
    {
        $reason = $this->describeUnavailable();
        if ($reason !== null) {
            throw new RuntimeException($reason);
        }
        $command = $this->buildOpenCommand($workerArguments, $nonce);
        $null = $this->platform === 'Windows' ? 'NUL' : '/dev/null';
        // Windows worker reopens CONIN$/CONOUT$ rather than using inherited GUI handles.
        $descriptors = $this->platform === 'Windows' ? [] : [0 => ['file', $null, 'r'], 1 => ['file', $null, 'w'], 2 => ['file', $null, 'w']];
        $process = ($this->commandRunner)($command['arguments'], $descriptors, $command['options']);
        if (! is_resource($process)) {
            throw new RuntimeException('Could not open the owned Terminal window.');
        }

        return new TerminalSessionProcess($process, $this->platform);
    }

    /** @param list<string> $workerArguments
     * @return array{arguments: list<string>, options: array<string, mixed>}
     */
    public function buildOpenCommand(array $workerArguments, string $nonce): array
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            throw new RuntimeException('Invalid terminal window ownership token.');
        }
        $title = 'Ichiloto Play ' . $nonce;
        return match ($this->platform) {
            'Darwin' => [
                'arguments' => [($this->findExecutable)('osascript') ?? '/usr/bin/osascript', '-e', $this->buildMacOpenScript($workerArguments, $title)],
                'options' => [],
            ],
            'Linux' => ['arguments' => [($this->findExecutable)('xterm') ?? 'xterm', '-T', $title, '-e', ...$workerArguments], 'options' => []],
            'Windows' => ['arguments' => $workerArguments, 'options' => ['create_new_console' => true, 'create_process_group' => true]],
            default => throw new RuntimeException('Unsupported Terminal platform.'),
        };
    }

    public function closeOwnedWindow(string $nonce, bool $wait = true): void
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            throw new RuntimeException('Invalid terminal window ownership token.');
        }
        if ($this->platform !== 'Darwin') {
            return; // xterm and Windows close when their own worker exits.
        }
        $process = ($this->commandRunner)([($this->findExecutable)('osascript') ?? '/usr/bin/osascript', '-e', $this->buildMacCloseScript($nonce)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', '/dev/null', 'w'], 2 => ['file', '/dev/null', 'w']], []);
        if (is_resource($process) && $wait) {
            $owned = new TerminalSessionProcess($process, $this->platform);
            $deadline = microtime(true) + 3.0;
            while ($owned->isRunning() && microtime(true) < $deadline) {
                usleep(20000);
            }
            $owned->isRunning() ? $owned->stop() : $owned->close();
        }
        // A worker schedules this bounded closer just before exiting on owner death.
    }

    /** @param list<string> $arguments */
    private function buildMacOpenScript(array $arguments, string $title): string
    {
        $command = 'exec ' . implode(' ', array_map(self::quotePosixArgument(...), $arguments));

        return 'tell application "Terminal"' . "\n"
            . 'set ownedTab to do script ' . self::quoteAppleScript($command) . "\n"
            . 'set custom title of ownedTab to ' . self::quoteAppleScript($title) . "\n"
            . "activate\nend tell";
    }

    private function buildMacCloseScript(string $nonce): string
    {
        $title = self::quoteAppleScript('Ichiloto Play ' . $nonce);

        return "repeat 20 times\nif application \"Terminal\" is not running then return\n"
            . "tell application \"Terminal\"\nrepeat with ownedWindow in windows\n"
            . "if (count of tabs of ownedWindow) is 1 then\n"
            . "if custom title of tab 1 of ownedWindow is {$title} then\n"
            . "if busy of tab 1 of ownedWindow is false then\nclose ownedWindow saving no\nreturn\nend if\nend if\nend if\n"
            . "end repeat\nend tell\ndelay 0.1\nend repeat";
    }

    private static function quotePosixArgument(string $argument): string
    {
        return "'" . str_replace("'", "'\\''", $argument) . "'";
    }

    private static function quoteAppleScript(string $value): string
    {
        return '"' . str_replace(['\\', '"', "\r", "\n"], ['\\\\', '\\"', '\\r', '\\n'], $value) . '"';
    }

    private static function findExecutable(string $name): ?string
    {
        if (is_file($name) && is_executable($name)) {
            return $name;
        }
        foreach (explode(PATH_SEPARATOR, getenv('PATH') ?: '') as $directory) {
            if ($directory !== '' && is_file($directory . DIRECTORY_SEPARATOR . $name) && is_executable($directory . DIRECTORY_SEPARATOR . $name)) {
                return $directory . DIRECTORY_SEPARATOR . $name;
            }
        }

        return null;
    }
}
