<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

final class GameLaunchCommandBuilder
{
    public const RENDERER_ENVIRONMENT_VARIABLE = 'ICHILOTO_RENDERER';
    public const STARTUP_ENVIRONMENT_VARIABLE = 'ICHILOTO_START_MODE';
    public const LAUNCH_CONTEXT_ENVIRONMENT_VARIABLE = 'ICHILOTO_LAUNCH_CONTEXT';
    public const MAX_LAUNCH_CONTEXT_BYTES = 65536;

    /** @return array<string, string> */
    public function buildEnvironment(string $rendererId, ?string $startMode = null, ?string $launchContext = null): array
    {
        $environment = getenv();
        $environment = is_array($environment) ? $environment : [];
        unset($environment[self::LAUNCH_CONTEXT_ENVIRONMENT_VARIABLE]);
        $environment[self::RENDERER_ENVIRONMENT_VARIABLE] = $rendererId;
        if ($launchContext !== null) {
            $this->validateLaunchContextTransport($launchContext);
            $this->rejectLaunchContextModeConflict($startMode);
            unset($environment[self::STARTUP_ENVIRONMENT_VARIABLE]);
            $environment[self::LAUNCH_CONTEXT_ENVIRONMENT_VARIABLE] = $launchContext;
        }
        if ($startMode !== null) {
            $this->validateStartMode($startMode);
            $environment[self::STARTUP_ENVIRONMENT_VARIABLE] = $startMode;
        }

        return $environment;
    }

    /** Console owns transport shape only; the project Engine owns the schema. */
    public function validateLaunchContextTransport(string $json): void
    {
        if (strlen($json) > self::MAX_LAUNCH_CONTEXT_BYTES) {
            throw new \InvalidArgumentException('Launch context exceeds 65536 bytes.');
        }
        try {
            $object = json_decode($json, flags: JSON_THROW_ON_ERROR);
        } catch (\JsonException $error) {
            throw new \InvalidArgumentException('Launch context must be a JSON object.', previous: $error);
        }
        if (!$object instanceof \stdClass) {
            throw new \InvalidArgumentException('Launch context must be a JSON object.');
        }
    }

    public function rejectLaunchContextModeConflict(?string $startMode): void
    {
        if ($startMode !== null || getenv(self::STARTUP_ENVIRONMENT_VARIABLE) !== false) {
            throw new \InvalidArgumentException('Launch context cannot be combined with --start or ICHILOTO_START_MODE.');
        }
    }

    public function validateStartMode(string $startMode): void
    {
        if (!in_array($startMode, ['title', 'new-game'], true)) {
            throw new \InvalidArgumentException('Unknown game start mode. Use title or new-game.');
        }
    }

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

    public function buildGameCommand(string $mainFile, string $errorLogFile, string $rendererId, ?string $startMode = null, ?string $launchContext = null): string
    {
        $arguments = array_map(
            static fn (string $argument): string => escapeshellarg($argument),
            $this->buildGameCommandArguments($mainFile, $errorLogFile),
        );

        $command = sprintf(
            '%s=%s %s 2>> %s',
            self::RENDERER_ENVIRONMENT_VARIABLE,
            escapeshellarg($rendererId),
            implode(' ', $arguments),
            escapeshellarg($errorLogFile),
        );
        if ($startMode !== null) {
            $this->validateStartMode($startMode);
            $command = self::STARTUP_ENVIRONMENT_VARIABLE . '=' . escapeshellarg($startMode) . ' ' . $command;
        }

        // A tmux server may hold stale startup intent independently of this Console.
        if ($launchContext !== null) {
            $this->validateLaunchContextTransport($launchContext);
            $this->rejectLaunchContextModeConflict($startMode);
            $command = 'env -u ' . self::STARTUP_ENVIRONMENT_VARIABLE . ' '
                . self::LAUNCH_CONTEXT_ENVIRONMENT_VARIABLE . '=' . escapeshellarg($launchContext) . ' ' . $command;
        } else {
            $command = 'env -u ' . self::LAUNCH_CONTEXT_ENVIRONMENT_VARIABLE . ' ' . $command;
        }

        return $command;
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
