<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Exceptions\UnsupportedProjectFormatException;

/**
 * Asks the engine whether it reads a project's recorded format, so commands
 * can say what is outdated and point to `ichiloto upgrade` before they read
 * data written for another format.
 */
final class ProjectFormatCheck
{
    /**
     * The engine's explanation when the project's format is not the one it
     * reads; null when it is, or when the loaded engine predates formats.
     */
    public static function getProblem(string $projectRoot): ?string
    {
        $configPath = rtrim($projectRoot, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiloto.json';

        if (! is_file($configPath) || ! class_exists(ProjectFormat::class)) {
            return null;
        }

        $config = json_decode((string) file_get_contents($configPath), true);

        try {
            ProjectFormat::assertSupported(is_array($config) ? ($config[ProjectFormat::KEY] ?? null) : null);
        } catch (UnsupportedProjectFormatException $exception) {
            return $exception->getMessage();
        }

        return null;
    }
}
