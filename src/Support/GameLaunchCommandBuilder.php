<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

final class GameLaunchCommandBuilder
{
    public const RENDERER_ENVIRONMENT_VARIABLE = 'ICHILOTO_RENDERER';

    /**
     * @return list<string>
     */
    public function buildGameCommandArguments(string $mainFile, string $errorLogFile): array
    {
        return [
            PHP_BINARY,
            '-d',
            'display_errors=0',
            '-d',
            'display_startup_errors=0',
            '-d',
            'log_errors=1',
            '-d',
            'error_log=' . $errorLogFile,
            $mainFile,
        ];
    }

    public function buildGameCommand(string $mainFile, string $errorLogFile, string $rendererId): string
    {
        $arguments = array_map(
            static fn (string $argument): string => escapeshellarg($argument),
            $this->buildGameCommandArguments($mainFile, $errorLogFile),
        );

        return sprintf(
            '%s=%s %s 2>> %s',
            self::RENDERER_ENVIRONMENT_VARIABLE,
            escapeshellarg($rendererId),
            implode(' ', $arguments),
            escapeshellarg($errorLogFile),
        );
    }

    public function buildCrashPreservingCommand(string $command, string $label): string
    {
        $script = sprintf(
            '%s && exit 0 || { printf "
[%s exited with an error]
"; exec sh -l; }',
            $command,
            $label,
        );

        return sprintf('sh -lc %s', escapeshellarg($script));
    }

    public function buildTmuxNewSessionCommand(
        string $sessionName,
        string $workingDirectory,
        string $launchCommand,
    ): string {
        return sprintf(
            'tmux new-session -d -s %s -c %s %s',
            escapeshellarg($sessionName),
            escapeshellarg($workingDirectory),
            escapeshellarg($launchCommand),
        );
    }
}
