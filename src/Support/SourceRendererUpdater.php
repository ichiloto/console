<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/** Performs an explicitly requested build and verified installation. */
final class SourceRendererUpdater
{
    private const float DEFAULT_BUILD_TIMEOUT_SECONDS = 1800;
    private const float LOCK_TIMEOUT_SECONDS = 10;
    private const int LOCK_RETRY_MICROSECONDS = 50000;
    private const int NANOSECONDS_PER_SECOND = 1_000_000_000;

    public function __construct(
        private readonly SourceRendererUpdateChecker $checker = new SourceRendererUpdateChecker(),
        private readonly RendererPackageInstaller $installer = new RendererPackageInstaller(),
        private readonly RendererPreparationProcess $process = new RendererPreparationProcess(),
        private readonly float $buildTimeoutSeconds = self::DEFAULT_BUILD_TIMEOUT_SECONDS,
        private readonly float $lockTimeoutSeconds = self::LOCK_TIMEOUT_SECONDS,
    ) {
    }

    /** Returns whether a package was installed; false means it was already current. */
    public function update(string $projectDirectory, string $renderer, OutputInterface $output): bool
    {
        $candidate = $this->checker->check($projectDirectory, $renderer);
        if ($candidate === null) {
            throw new RuntimeException('No development source is declared for renderer ' . $renderer . ' in this project Engine.');
        }
        $installed = $candidate->boundary . '/installed';
        $this->ensureDirectory($installed);
        $lockFile = $installed . '/.source-update.lock';
        if (is_link($lockFile)) { throw new RuntimeException('Renderer update lock must not be a symbolic link.'); }
        $lock = @fopen($lockFile, 'c');
        if ($lock === false) { throw new RuntimeException('Cannot lock renderer updates in ' . $installed); }
        $temporary = null;
        try {
            if (! is_finite($this->lockTimeoutSeconds) || $this->lockTimeoutSeconds <= 0) {
                throw new RuntimeException('Renderer update lock timeout must be positive and finite.');
            }
            $deadline = hrtime(true) / self::NANOSECONDS_PER_SECOND + $this->lockTimeoutSeconds;
            while (! flock($lock, LOCK_EX | LOCK_NB)) {
                if (hrtime(true) / self::NANOSECONDS_PER_SECOND >= $deadline) {
                    throw new RuntimeException('Renderer update lock timed out. Another update may be in progress.');
                }
                usleep(self::LOCK_RETRY_MICROSECONDS);
            }
            // Recheck after locking: another process may have installed this fingerprint.
            $update = $this->checker->check($projectDirectory, $renderer);
            if ($update === null) { throw new RuntimeException('The declared renderer source is no longer available.'); }
            if ($update->current) {
                $this->clearSkip($update);
                return false;
            }
            $temporary = sys_get_temp_dir() . '/ichiloto-renderer-update-' . bin2hex(random_bytes(12));
            if (! @mkdir($temporary, 0700)) { throw new RuntimeException('Cannot create private renderer update directory.'); }
            $temporary = realpath($temporary) ?: $temporary;
            $description = $this->checker->describe($update->source, $update->builder, $renderer, $temporary);
            if ($description['fingerprint'] !== $update->fingerprint) {
                throw new RuntimeException('Renderer source changed during update checking; retry the update.');
            }
            $output->writeln('<info>Building the ' . $renderer . ' renderer for ' . $update->platform . '…</info>');
            $this->process->run([PHP_BINARY, $update->builder, '--out=' . $temporary], $update->source,
                $output, $this->buildTimeoutSeconds);
            if ($this->checker->describe($update->source, $update->builder, $renderer, $temporary) !== $description) {
                throw new RuntimeException('Renderer source changed during the build; the existing installation was preserved.');
            }
            $package = realpath($description['packageDirectory']);
            if ($package === false || ! str_starts_with($package, $temporary . DIRECTORY_SEPARATOR) || ! is_dir($package)) {
                throw new RuntimeException('The builder did not produce its declared package inside the private output directory.');
            }
            $descriptorFile = $package . '/renderer-package.json';
            $descriptorContents = @file_get_contents($descriptorFile);
            if ($descriptorContents === false) { throw new RuntimeException('The built package has no readable descriptor.'); }
            $descriptor = json_decode($descriptorContents, true, flags: JSON_THROW_ON_ERROR);
            if (! is_array($descriptor) || ($descriptor['renderer'] ?? null) !== $renderer
                || ($descriptor['platform'] ?? null) !== $update->platform) {
                throw new RuntimeException('The built package does not match the selected renderer and host platform.');
            }
            $this->installer->install($package, $update->boundary);
            $receipt = [...$update->getIdentity(), 'executable' => $descriptor['executable'], 'files' => $descriptor['files']];
            try {
                $this->writeJson($update->getReceiptFile(), $receipt);
            } catch (\Throwable $error) {
                $output->writeln('<comment>The renderer was installed, but its update receipt could not be saved: '
                    . OutputFormatter::escape($error->getMessage()) . '</comment>');
            }
            try {
                $this->clearSkip($update);
            } catch (\Throwable $error) {
                $output->writeln('<comment>The renderer was installed, but its skipped-version record could not be cleared: '
                    . OutputFormatter::escape($error->getMessage()) . '</comment>');
            }
            return true;
        } finally {
            if ($temporary !== null && is_dir($temporary)) {
                try { $this->removeTemporaryDirectory($temporary); }
                catch (\Throwable $error) {
                    $output->writeln('<comment>The temporary renderer package could not be removed: '
                        . OutputFormatter::escape($error->getMessage()) . '</comment>');
                }
            }
            flock($lock, LOCK_UN);
            fclose($lock);
        }
    }

    public function skip(SourceRendererUpdate $update): void
    {
        if ($update->current) { return; }
        $this->ensureDirectory($update->boundary . '/installed');
        $this->writeJson($update->getSkipFile(), $update->getIdentity());
    }

    private function clearSkip(SourceRendererUpdate $update): void
    {
        $skipFile = $update->getSkipFile();
        if ((is_file($skipFile) || is_link($skipFile)) && ! @unlink($skipFile)) {
            throw new RuntimeException('The renderer was installed, but its skipped-version record could not be cleared.');
        }
    }

    /** @param array<string, mixed> $value */
    private function writeJson(string $file, array $value): void
    {
        $temporary = $file . '.tmp-' . bin2hex(random_bytes(6));
        try {
            if (@file_put_contents($temporary, json_encode($value, JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . "\n") === false
                || ! @rename($temporary, $file)) {
                throw new RuntimeException('Cannot save renderer update state in ' . dirname($file));
            }
        } finally {
            if (is_file($temporary)) { @unlink($temporary); }
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (is_link($directory)) { throw new RuntimeException('Renderer installation directory must not be a symbolic link.'); }
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException('Cannot create renderer installation directory: ' . $directory);
        }
    }

    private function removeTemporaryDirectory(string $directory): void
    {
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($directory, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($entries as $entry) {
            $removed = $entry->isDir() && ! $entry->isLink()
                ? @rmdir($entry->getPathname()) : @unlink($entry->getPathname());
            if (! $removed) { throw new RuntimeException('Cannot remove ' . $entry->getPathname()); }
        }
        if (! @rmdir($directory)) { throw new RuntimeException('Cannot remove ' . $directory); }
    }
}
