<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

use RuntimeException;

/** Replaces project files whole, so a reader never sees half a file. */
final class ProjectFileWriter
{
    public function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);
        $permissions = is_file($path)
            ? (fileperms($path) & 0777)
            : (0666 & ~umask());

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $temporaryPath = tempnam($directory, '.ichiloto-upgrade-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException("Unable to prepare an atomic write for {$path}.");
        }

        try {
            if (file_put_contents($temporaryPath, $contents) === false
                || ! chmod($temporaryPath, $permissions)
                || ! rename($temporaryPath, $path)) {
                throw new RuntimeException("Unable to write {$path}.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
