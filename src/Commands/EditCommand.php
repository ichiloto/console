<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Launch\EditorLaunchTargets;
use Ichiloto\Console\Launch\LaunchRequest;
use Ichiloto\Console\Launch\LaunchTargetSelector;
use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Console\Support\GuiEditorLocator;
use Ichiloto\Console\Support\TerminalInteractivity;
use Ichiloto\Console\Upgrade\ProjectFormatStatus;
use Ichiloto\Editor\Editor;
use InvalidArgumentException;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'edit',
    description: 'Launch the given project in the editor.',
)]
class EditCommand extends Command
{
    private readonly GuiEditorLocator $guiEditorLocator;

    private readonly EditorLaunchTargets $editorTargets;

    public function __construct(
        private readonly LaunchTargetSelector $launchTargetSelector = new LaunchTargetSelector(),
        private readonly TerminalInteractivity $terminalInteractivity = new TerminalInteractivity(),
        ?GuiEditorLocator $guiEditorLocator = null,
    ) {
        $this->guiEditorLocator = $guiEditorLocator ?? GuiEditorLocator::fromEnvironment(dirname(__DIR__, 2));
        $this->editorTargets = new EditorLaunchTargets($this->guiEditorLocator);

        parent::__construct();
    }

    /**
     * @inheritDoc
     */
    public function configure(): void
    {
        $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory to open.');
        $this->addOption('no-tmux', null, InputOption::VALUE_NONE, 'Launch directly without creating or reusing a tmux session.');
        LaunchRequest::addOptions($this, $this->editorTargets, 'Editor to open', 'editors');
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

        try {
            $formatProblem = ProjectFormatStatus::read($workingDirectory)->problem;
        } catch (Throwable $throwable) {
            $output->writeln($throwable->getMessage());
            return Command::FAILURE;
        }

        if ($formatProblem !== null) {
            // The editor reads the project with its Engine; say what fixes it before opening any editor.
            $output->writeln('<error> ! </error> ' . OutputFormatter::escape($formatProblem));
            return Command::FAILURE;
        }

        try {
            $editorId = $this->launchTargetSelector->selectTarget(
                request: LaunchRequest::fromInput($input),
                targets: $this->editorTargets,
                projectDirectory: $workingDirectory,
                canPrompt: $input->isInteractive() && $this->terminalInteractivity->supportsPrompts(),
            );
        } catch (RuntimeException|InvalidArgumentException $exception) {
            $output->writeln(OutputFormatter::escape($exception->getMessage()));
            return Command::INVALID;
        }

        if ($editorId === LaunchRequest::GRAPHICAL_ID) {
            return $this->launchGui($workingDirectory, $output);
        }

        return $this->openTerminalEditor($workingDirectory, (bool) $input->getOption('no-tmux'), $output);
    }

    /**
     * Opens the project in the terminal editor, inside a tmux session unless
     * `$noTmux` is set or the terminal cannot host one.
     */
    protected function openTerminalEditor(string $workingDirectory, bool $noTmux, OutputInterface $output): int
    {
        if (! $noTmux && $this->shouldLaunchInTmux()) {
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
            '%s %s edit --no-tmux --renderer=%s -d .',
            escapeshellcmd(PHP_BINARY),
            escapeshellarg($binPath),
            LaunchRequest::TERMINAL_ID,
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
        $executable = $this->guiEditorLocator->locate();

        if ($executable === null) {
            $output->writeln($this->guiEditorLocator->describeMissing());

            return Command::FAILURE;
        }

        return $this->openGraphicalEditor($executable, realpath($workingDirectory) ?: $workingDirectory);
    }

    /** Runs the located GUI editor on the project, with `edit:host` as its session host. */
    protected function openGraphicalEditor(string $executable, string $project): int
    {
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
