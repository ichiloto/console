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
    /** The variable the editor's playtest reads to find the console that plays the game. */
    public const string CONSOLE_BINARY_ENVIRONMENT = 'ICHILOTO_CONSOLE_BIN';

    /** Loads the engine and registers the project's classes. */
    public static function prepare(string $projectDirectory): void
    {
        load_engine_autoloader($projectDirectory);
        self::registerProjectAutoload($projectDirectory);
        self::nameConsoleBinary();
    }

    /**
     * Tells the editor which console opened it, so its playtest plays with
     * the same console rather than another one found on the project or the
     * PATH. An author who names one keeps it.
     */
    public static function nameConsoleBinary(): void
    {
        if (getenv(self::CONSOLE_BINARY_ENVIRONMENT) === false) {
            putenv(self::CONSOLE_BINARY_ENVIRONMENT . '=' . self::getConsoleBinary());
        }
    }

    /** This console's entry point. */
    public static function getConsoleBinary(): string
    {
        return dirname(__DIR__, 2) . DIRECTORY_SEPARATOR . 'bin' . DIRECTORY_SEPARATOR . 'ichiloto';
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
