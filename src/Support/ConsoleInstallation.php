<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Composer\InstalledVersions;

/**
 * Where this Console is installed, so a message that needs a newer Console
 * names the update for this installation: a checkout of the Console itself,
 * or the Composer project (a global install included) that requires it.
 */
final readonly class ConsoleInstallation
{
    public const string PACKAGE = 'ichiloto/console';

    /**
     * @param string $consoleRoot This Console's package directory.
     * @param array<array-key, mixed> $installations Composer's installed data, one entry per vendor directory.
     */
    public function __construct(
        private string $consoleRoot,
        private array $installations,
    ) {
    }

    public static function detect(): self
    {
        return new self(dirname(__DIR__, 2), class_exists(InstalledVersions::class) ? InstalledVersions::getAllRawData() : []);
    }

    /** What to do to update this Console, as an instruction that completes a sentence. */
    public function getUpdateInstruction(): string
    {
        $consoleRoot = self::normalizePath($this->consoleRoot);

        foreach ($this->installations as $installation) {
            $installPath = is_array($installation) ? ($installation['versions'][self::PACKAGE]['install_path'] ?? null) : null;

            if (! is_string($installPath) || self::normalizePath($installPath) !== $consoleRoot) {
                continue;
            }

            $root = $installation['root'] ?? [];
            $composerRoot = is_array($root) && is_string($root['install_path'] ?? null)
                ? self::normalizePath($root['install_path'])
                : $consoleRoot;

            if (is_array($root) && ($root['name'] ?? null) === self::PACKAGE) {
                return "pull the latest Ichiloto Console into {$composerRoot}, then run `composer install` there";
            }

            return "run `composer update " . self::PACKAGE . " --with-all-dependencies` in {$composerRoot}";
        }

        return "update the Ichiloto Console installed in {$consoleRoot}";
    }

    /**
     * The path with `.` and `..` resolved. Composer records install paths
     * relative to its own directory, with either separator on Windows.
     */
    private static function normalizePath(string $path): string
    {
        $real = realpath($path);

        if (is_string($real)) {
            return $real;
        }

        $separator = str_contains($path, '\\') ? '\\' : '/';
        $segments = [];

        foreach (preg_split('#[\\\\/]+#', $path) ?: [] as $index => $segment) {
            if ($segment === '.' || ($segment === '' && $index > 0)) {
                continue;
            }

            if ($segment === '..' && $segments !== [] && end($segments) !== '..' && end($segments) !== '') {
                array_pop($segments);
                continue;
            }

            $segments[] = $segment;
        }

        return implode($separator, $segments);
    }
}
