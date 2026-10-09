<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Console\Support\GuiEditorLocator;
use Ichiloto\Editor\Editor;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'edit',
    description: 'Launch the given project in the editor.',
)]
final class EditCommand extends Command
{
    /**
     * @inheritDoc
     */
    public function configure(): void
    {
        $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory to open.');
        $this->addOption('no-tmux', null, InputOption::VALUE_NONE, 'Launch directly without creating or reusing a tmux session.');
        $this->addOption('gui', null, InputOption::VALUE_NONE, 'Open the project in the graphical editor instead of the terminal editor.');
    }

    /**
     * @inheritDoc
     */
    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDirectory = $input->getOption('directory') ?? getcwd() ?: '.';

        if (is_not_valid_working_dir($workingDirectory)) {
            $output->writeln('The working directory is not valid: ' . $workingDirectory);
            return Command::FAILURE;
        }

        if ((bool) $input->getOption('gui')) {
            return $this->launchGui($workingDirectory, $output);
        }

        if (! (bool) $input->getOption('no-tmux') && $this->shouldLaunchInTmux()) {
            return $this->launchInTmux($workingDirectory);
        }

        try {
            EditorProjectBootstrap::prepare($workingDirectory);
            (new Editor($workingDirectory))->run();
        } catch (Throwable $throwable) {
            $output->writeln($throwable->getMessage());
            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    /**
     * Determines whether the editor should use a tmux session.
     *
     * @return bool
     */
    private function shouldLaunchInTmux(): bool
    {
        if ((string) getenv('TMUX') !== '') {
            return false;
        }

        if (trim((string) shell_exec('command -v tmux 2>/dev/null')) === '') {
            return false;
        }

        if (function_exists('stream_isatty')) {
            return stream_isatty(STDIN) && stream_isatty(STDOUT);
        }

        return true;
    }

    /**
     * Launches the editor inside a tmux session.
     *
     * @param string $workingDirectory The project directory to open.
     * @return int
     */
    private function launchInTmux(string $workingDirectory): int
    {
        $binPath = dirname(__DIR__, 2) . '/bin/ichiloto';
        $sessionName = 'ichiloto-editor-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', basename($workingDirectory));
        $editorCommand = sprintf(
            '%s %s edit --no-tmux -d .',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($binPath),
        );
        $launchCommand = $this->buildCrashPreservingCommand($editorCommand, 'Ichiloto editor');

        if (! $this->tmuxSessionExists($sessionName)) {
            passthru(sprintf(
                'tmux new-session -d -s %s -c %s %s',
                escapeshellarg($sessionName),
                escapeshellarg($workingDirectory),
                escapeshellarg($launchCommand),
            ), $exitCode);

            if ($exitCode !== 0) {
                return $exitCode;
            }
        }

        $this->applyTmuxSessionOptions($sessionName, $this->shouldShowTmuxStatus($workingDirectory));
        passthru(sprintf('tmux attach-session -t %s', escapeshellarg($sessionName)), $exitCode);

        return $exitCode;
    }

    /**
     * Builds a shell command that preserves crash output inside tmux.
     *
     * @param string $command The wrapped command.
     * @param string $label The user-facing process label.
     * @return string
     */
    private function buildCrashPreservingCommand(string $command, string $label): string
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

    /**
     * Applies tmux options that help preserve crash output.
     *
     * @param string $sessionName The tmux session name.
     * @param bool $showStatus Whether to show the tmux status bar.
     * @return void
     */
    private function applyTmuxSessionOptions(string $sessionName, bool $showStatus): void
    {
        shell_exec(sprintf('tmux set-option -t %s status %s 2>/dev/null', escapeshellarg($sessionName), $showStatus ? 'on' : 'off'));
        shell_exec(sprintf('tmux set-option -t %s alternate-screen off 2>/dev/null', escapeshellarg($sessionName)));
    }

    /**
     * Checks whether a tmux session already exists.
     *
     * @param string $sessionName The session name.
     * @return bool
     */
    private function tmuxSessionExists(string $sessionName): bool
    {
        exec(sprintf('tmux has-session -t %s 2>/dev/null', escapeshellarg($sessionName)), $output, $exitCode);

        return $exitCode === 0;
    }

    /**
     * Determines whether tmux should show its status bar for the project.
     *
     * @param string $workingDirectory The project root.
     * @return bool
     */
    private function shouldShowTmuxStatus(string $workingDirectory): bool
    {
        $configPath = rtrim($workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiloto.json';

        if (! is_file($configPath)) {
            return false;
        }

        $config = json_decode((string) file_get_contents($configPath), true);

        if (! is_array($config)) {
            return false;
        }

        $debug = $config['debug'] ?? [];

        if (! is_array($debug)) {
            return false;
        }

        return (bool) ($debug['enabled'] ?? false) && (bool) ($debug['show'] ?? false);
    }

    /**
     * Opens the project in the graphical editor: the native window, with this
     * console's `edit:host` as the session host it edits through. The
     * terminal editor is untouched and stays available.
     */
    private function launchGui(string $workingDirectory, OutputInterface $output): int
    {
        $locator = GuiEditorLocator::fromEnvironment(dirname(__DIR__, 2));
        $executable = $locator->locate();

        if ($executable === null) {
            $output->writeln($locator->describeMissing());

            return Command::FAILURE;
        }

        $project = realpath($workingDirectory) ?: $workingDirectory;
        $command = [
            $executable,
            '--project', $project,
            '--',
            PHP_BINARY, dirname(__DIR__, 2) . '/bin/ichiloto', 'edit:host', '-d', $project,
        ];
        passthru(implode(' ', array_map('escapeshellarg', $command)), $exitCode);

        return $exitCode === 0 ? Command::SUCCESS : Command::FAILURE;
    }
}
