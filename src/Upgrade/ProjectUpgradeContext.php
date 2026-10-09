<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

use RuntimeException;

/** The project being upgraded and the choices the person made for it. */
final readonly class ProjectUpgradeContext
{
    public const string CONFIG_FILENAME = 'ichiloto.json';

    public string $root;

    /**
     * @param string $projectRoot The project directory.
     * @param string|null $requestedId The save identity to use when the project has none.
     */
    public function __construct(string $projectRoot, public ?string $requestedId = null)
    {
        $root = realpath($projectRoot);

        if (! is_string($root) || ! is_dir($root)) {
            throw new RuntimeException("The project directory does not exist: {$projectRoot}");
        }

        if (! is_file($root . DIRECTORY_SEPARATOR . self::CONFIG_FILENAME)) {
            throw new RuntimeException(self::CONFIG_FILENAME . " was not found in {$root}.");
        }

        $this->root = $root;
    }

    /** The absolute path of a project-relative path written with forward slashes. */
    public function getPath(string $relativePath): string
    {
        return $this->root . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, ltrim($relativePath, '/'));
    }

    /** The project-relative path, with forward slashes, of a path inside the project. */
    public function getRelativePath(string $path): string
    {
        $prefix = $this->root . DIRECTORY_SEPARATOR;

        if (str_starts_with($path, $prefix)) {
            $path = substr($path, strlen($prefix));
        }

        return str_replace(DIRECTORY_SEPARATOR, '/', $path);
    }
}
