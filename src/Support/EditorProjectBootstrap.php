<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

/**
 * Prepares this process to open a project in the editor, whichever interface
 * edits it: the engine the project's data files reference, and the project's
 * own PSR-4 classes for asset inspection.
 */
final class EditorProjectBootstrap
{
    /** Loads the engine and registers the project's classes. */
    public static function prepare(string $projectDirectory): void
    {
        load_engine_autoloader($projectDirectory);
        self::registerProjectAutoload($projectDirectory);
    }

    /** Registers the opened project's PSR-4 autoload rules for editor asset inspection. */
    public static function registerProjectAutoload(string $projectDirectory): void
    {
        $composerPath = rtrim($projectDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'composer.json';

        if (! is_file($composerPath)) {
            return;
        }

        $composer = json_decode((string) file_get_contents($composerPath), true);
        $autoloadRules = $composer['autoload']['psr-4'] ?? [];

        if (! is_array($autoloadRules) || $autoloadRules === []) {
            return;
        }

        spl_autoload_register(static function (string $class) use ($projectDirectory, $autoloadRules): void {
            foreach ($autoloadRules as $namespace => $paths) {
                if (! is_string($namespace) || ! str_starts_with($class, $namespace)) {
                    continue;
                }

                $relativeClass = str_replace('\\', DIRECTORY_SEPARATOR, substr($class, strlen($namespace))) . '.php';

                foreach ((array) $paths as $path) {
                    $filename = rtrim($projectDirectory, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . trim((string) $path, DIRECTORY_SEPARATOR)
                        . DIRECTORY_SEPARATOR
                        . $relativeClass;

                    if (is_file($filename)) {
                        require_once $filename;
                    }
                }
            }
        });
    }
}
