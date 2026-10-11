<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

final readonly class GameProcessLauncher
{
    public function __construct(private GameLaunchCommandBuilder $commandBuilder)
    {
    }

    /**
     * Runs a game with the Console process's terminal streams inherited by
     * the child. A null result means the process could not be started.
     */
    public function launch(
        string $workingDirectory,
        string $mainFile,
        string $errorLogFile,
        string $rendererId,
        ?string $startMode = null,
    ): ?int {
        $environment = $this->commandBuilder->buildEnvironment($rendererId, $startMode);
        $errorStream = @fopen($errorLogFile, 'ab');

        if (! is_resource($errorStream)) {
            return null;
        }

        $pipes = [];

        try {
            $process = @proc_open(
                $this->commandBuilder->buildGameCommandArguments($mainFile, $errorLogFile),
                [
                    0 => STDIN,
                    1 => STDOUT,
                    2 => $errorStream,
                ],
                $pipes,
                $workingDirectory,
                $environment,
                ['bypass_shell' => true],
            );

            if (! is_resource($process)) {
                return null;
            }

            return proc_close($process);
        } finally {
            fclose($errorStream);
        }
    }
}
