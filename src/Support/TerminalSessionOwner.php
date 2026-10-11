<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Closure;
use RuntimeException;

/** Watches the original editor/console parent, rather than a reused process id. */
final class TerminalSessionOwner
{
    private int $parentPid;
    private ?string $identity = null;
    private Closure $queryWindows;

    /** @param callable(int, bool): ?array|null $queryWindows */
    public function __construct(private string $platform = PHP_OS_FAMILY, ?callable $queryWindows = null)
    {
        $this->queryWindows = $queryWindows !== null ? Closure::fromCallable($queryWindows)
            : static function (int $pid, bool $parent): ?array {
                $script = '$p = Get-CimInstance Win32_Process -Filter "ProcessId = ' . $pid . '"; '
                    . ($parent ? '$p = Get-CimInstance Win32_Process -Filter ("ProcessId = " + $p.ParentProcessId); ' : '')
                    . '$p | Select-Object ProcessId, CreationDate | ConvertTo-Json -Compress';
                $output = TerminalSessionProcess::captureOutput([TerminalSessionProcess::getWindowsPowerShell(), '-NoLogo', '-NoProfile', '-NonInteractive', '-Command', $script]);
                $value = $output !== null ? json_decode($output, true) : null;

                return is_array($value) ? $value : null;
            };
        if ($this->platform === 'Windows') {
            $parent = ($this->queryWindows)(getmypid(), true);
            if (! is_int($parent['ProcessId'] ?? null) || ! is_string($parent['CreationDate'] ?? null)) {
                throw new RuntimeException('Cannot establish the owning Windows parent process; refusing an unowned Terminal session.');
            }
            $this->parentPid = $parent['ProcessId'];
            $this->identity = $parent['CreationDate'];
        } else {
            $this->parentPid = posix_getppid();
        }
    }

    public function isAlive(): bool
    {
        if ($this->platform !== 'Windows') {
            return posix_getppid() === $this->parentPid;
        }
        $parent = ($this->queryWindows)($this->parentPid, false);

        return ($parent['ProcessId'] ?? null) === $this->parentPid && ($parent['CreationDate'] ?? null) === $this->identity;
    }
}
