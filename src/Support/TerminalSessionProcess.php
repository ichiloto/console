<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

/** Owns one direct child and its ordinary descendants, never a shared terminal app. */
final class TerminalSessionProcess
{
    /** @var array<int, string> pid => process start identity */
    private array $descendants = [];
    private ?int $exitCode = null;
    private bool $closed = false;
    private ?string $rootIdentity = null;

    /** @param resource $process */
    public function __construct(private $process, private string $platform = PHP_OS_FAMILY)
    {
    }

    /** @param list<string> $arguments
     * @param array<int, mixed> $descriptors
     * @param array<string, string>|null $environment
     * @param array<string, mixed> $options
     * @return resource|false
     */
    public static function start(array $arguments, array $descriptors, ?string $directory = null, ?array $environment = null, array $options = [])
    {
        $pipes = [];

        return @proc_open($arguments, $descriptors, $pipes, $directory, $environment, ['bypass_shell' => true, ...$options]);
    }

    public function isRunning(): bool
    {
        if ($this->closed) {
            return false;
        }
        $status = proc_get_status($this->process);
        if (! $status['running']) {
            $this->exitCode ??= $status['exitcode'] >= 0 ? $status['exitcode'] : ($status['signaled'] ? 128 + $status['termsig'] : null);
        }

        return $status['running'];
    }

    public function getProcessId(): int
    {
        return proc_get_status($this->process)['pid'];
    }

    public function getExitCode(): ?int
    {
        $this->isRunning();

        return $this->exitCode;
    }

    public function rememberDescendants(): void
    {
        if ($this->closed) {
            return;
        }
        $table = $this->readProcessTable();
        $root = $this->getProcessId();
        $this->rootIdentity ??= $table[$root]['identity'] ?? null;
        $parents = isset($table[$root]) && $table[$root]['identity'] === $this->rootIdentity ? [$root] : [];
        do {
            $next = [];
            foreach ($table as $pid => $entry) {
                if (in_array($entry['parent'], $parents, true)) {
                    $this->descendants[$pid] = $entry['identity'];
                    $next[] = $pid;
                }
            }
            $parents = $next;
        } while ($parents !== []);
    }

    public function stop(float $grace = 0.75): void
    {
        if ($this->closed) {
            return;
        }
        if ($this->platform === 'Windows') {
            $this->rememberDescendants();
            $table = $this->readProcessTable();
            foreach (array_reverse($this->descendants, true) as $pid => $identity) {
                if (($table[$pid]['identity'] ?? null) === $identity) {
                    $this->terminateWindowsTree($pid);
                }
            }
            if ($this->isRunning()) {
                $this->terminateWindowsTree($this->getProcessId());
                @proc_terminate($this->process);
            }
        } else {
            $this->rememberDescendants();
            $this->signalDescendants(15);
            if ($this->isRunning()) {
                @proc_terminate($this->process, 15);
            }
            $deadline = microtime(true) + $grace;
            while (microtime(true) < $deadline && ($this->isRunning() || $this->hasDescendants())) {
                usleep(20000);
            }
            $this->signalDescendants(9);
            if ($this->isRunning()) {
                @proc_terminate($this->process, 9);
            }
        }
        $this->close();
    }

    public function close(): void
    {
        if (! $this->closed) {
            $code = proc_close($this->process);
            $this->exitCode ??= $code >= 0 ? $code : null;
            $this->closed = true;
        }
    }

    private function signalDescendants(int $signal): void
    {
        $table = $this->readProcessTable();
        foreach (array_reverse($this->descendants, true) as $pid => $identity) {
            if (($table[$pid]['identity'] ?? null) === $identity) {
                @posix_kill($pid, $signal);
            }
        }
    }

    private function hasDescendants(): bool
    {
        $table = $this->readProcessTable();
        foreach ($this->descendants as $pid => $identity) {
            if (($table[$pid]['identity'] ?? null) === $identity) {
                return true;
            }
        }

        return false;
    }

    /** @return array<int, array{parent: int, identity: string}> */
    private function readProcessTable(): array
    {
        $table = [];
        if ($this->platform === 'Windows') {
            // Query locally, but emit only this owned tree (not every Windows process).
            $roots = implode(',', [$this->getProcessId(), ...array_keys($this->descendants)]);
            $script = '$all = @(Get-CimInstance Win32_Process); $ids = @(' . $roots . '); '
                . 'do { $new = @($all | Where-Object { $ids -contains $_.ParentProcessId -and $ids -notcontains $_.ProcessId }); '
                . '$ids += @($new | ForEach-Object { $_.ProcessId }) } while ($new.Count -gt 0); '
                . 'ConvertTo-Json -Compress -InputObject @($all | Where-Object { $ids -contains $_.ProcessId } | Select-Object ProcessId, ParentProcessId, CreationDate)';
            $output = self::captureOutput([self::getWindowsPowerShell(), '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $script]);
            $entries = $output !== null ? json_decode($output, true) : null;
            foreach (is_array($entries) ? $entries : [] as $entry) {
                if (is_array($entry) && is_int($entry['ProcessId'] ?? null) && is_string($entry['CreationDate'] ?? null)) {
                    $table[$entry['ProcessId']] = ['parent' => (int) $entry['ParentProcessId'], 'identity' => $entry['CreationDate']];
                }
            }

            return $table;
        }
        $output = self::captureOutput(['/bin/ps', '-axo', 'pid=,ppid=,lstart=']);
        foreach (explode("\n", (string) $output) as $line) {
            if (preg_match('/^\s*(\d+)\s+(\d+)\s+(.+)$/', $line, $match)) {
                $table[(int) $match[1]] = ['parent' => (int) $match[2], 'identity' => $match[3]];
            }
        }

        return $table;
    }

    public static function getWindowsPowerShell(): string
    {
        return (getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\WindowsPowerShell\\v1.0\\powershell.exe';
    }

    /** Bounded local system queries; output is bounded and never reaches a GUI terminal. */
    /** @param list<string> $arguments */
    public static function captureOutput(array $arguments): ?string
    {
        $null = PHP_OS_FAMILY === 'Windows' ? 'NUL' : '/dev/null';
        $pipes = [];
        $process = @proc_open($arguments, [0 => ['file', $null, 'r'], 1 => ['pipe', 'w'], 2 => ['file', $null, 'w']], $pipes, options: ['bypass_shell' => true]);
        if (! is_resource($process)) {
            return null;
        }
        stream_set_blocking($pipes[1], false);
        $deadline = microtime(true) + 2.0;
        $output = '';
        $status = proc_get_status($process);
        while ($status['running'] && microtime(true) < $deadline) {
            // Windows pipes are not reliably nonblocking; read only after process exit.
            if (PHP_OS_FAMILY !== 'Windows') {
                $output .= (string) fread($pipes[1], 65536);
            }
            if (strlen($output) > 1048576) {
                break;
            }
            usleep(10000);
            $status = proc_get_status($process);
        }
        if ($status['running']) {
            @proc_terminate($process, 9);
        } else {
            $output .= (string) stream_get_contents($pipes[1], 1048576);
        }
        fclose($pipes[1]);
        proc_close($process);

        return ! $status['running'] && $status['exitcode'] === 0 && strlen($output) <= 1048576 ? trim($output) : null;
    }

    private function terminateWindowsTree(int $pid): void
    {
        self::captureOutput([(getenv('SystemRoot') ?: 'C:\\Windows') . '\\System32\\taskkill.exe', '/PID', (string) $pid, '/T', '/F']);
    }
}
