<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

/**
 * Asks Git whether a project has uncommitted work, so an upgrade can be one
 * reviewable, reversible change instead of being mixed into unfinished edits.
 */
final class GitWorkingTree
{
    /**
     * The project's uncommitted paths, as `git status --porcelain` lists
     * them; null when the project is not in a Git working tree.
     *
     * @return list<string>|null
     */
    public function getUncommittedChanges(string $directory): ?array
    {
        $inside = $this->runGit($directory, ['rev-parse', '--is-inside-work-tree']);

        if ($inside === null || trim($inside) !== 'true') {
            return null;
        }

        $status = $this->runGit($directory, ['status', '--porcelain', '--untracked-files=all', '--', '.']);

        if ($status === null) {
            return null;
        }

        return array_values(array_filter(explode("\n", $status), static fn(string $line): bool => trim($line) !== ''));
    }

    /** @param list<string> $arguments */
    private function runGit(string $directory, array $arguments): ?string
    {
        $pipes = [];
        $process = @proc_open(
            ['git', '-C', $directory, ...$arguments],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
            $pipes,
        );

        if (! is_resource($process)) {
            return null;
        }

        fclose($pipes[0]);
        $output = (string) stream_get_contents($pipes[1]);
        stream_get_contents($pipes[2]);
        fclose($pipes[1]);
        fclose($pipes[2]);

        return proc_close($process) === 0 ? $output : null;
    }
}
