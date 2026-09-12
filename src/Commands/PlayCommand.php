<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\AppConfig;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Renderer\RendererSelector;
use Ichiloto\Console\Support\GameLaunchCommandBuilder;
use Ichiloto\Console\Support\TerminalInteractivity;
use Ichiloto\Console\Util\Path;
use InvalidArgumentException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'play',
    description: 'Play the game.'
)]
class PlayCommand extends Command
{
  private readonly RendererRegistry $rendererRegistry;

  private readonly RendererSelector $rendererSelector;

  private readonly TerminalInteractivity $terminalInteractivity;

  private readonly GameLaunchCommandBuilder $launchCommandBuilder;

  public function __construct(
    ?RendererRegistry $rendererRegistry = null,
    ?RendererSelector $rendererSelector = null,
    ?TerminalInteractivity $terminalInteractivity = null,
    ?GameLaunchCommandBuilder $launchCommandBuilder = null,
  ) {
    $this->rendererRegistry = $rendererRegistry ?? new RendererRegistry();
    $this->rendererSelector = $rendererSelector ?? new RendererSelector($this->rendererRegistry);
    $this->terminalInteractivity = $terminalInteractivity ?? new TerminalInteractivity();
    $this->launchCommandBuilder = $launchCommandBuilder ?? new GameLaunchCommandBuilder();

    parent::__construct();
  }

  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The directory to save the game data.');
    $this->addOption('no-tmux', null, InputOption::VALUE_NONE, 'Launch directly without creating or reusing a tmux session.');
    $this->addOption(
      'renderer',
      null,
      InputOption::VALUE_REQUIRED,
      sprintf('Renderer to use (%s)', implode(', ', $this->rendererRegistry->ids())),
    );
    $this->addOption('gpui-renderer', null, InputOption::VALUE_NONE, 'Use the GPUI renderer.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $directoryOption = $input->getOption('directory');
    $workingDirectory = is_string($directoryOption) ? $directoryOption : (getcwd() ?: '.');

    if (is_not_valid_working_dir($workingDirectory)) {
      $output->writeln('The working directory is not valid. ' . $workingDirectory);
      $output->writeln('Please make sure the working directory exists and contains the ichiloto.json file.', OutputInterface::VERBOSITY_VERBOSE);
      return Command::FAILURE;
    }

    $resolvedWorkingDirectory = realpath($workingDirectory);

    if ($resolvedWorkingDirectory === false) {
      $output->writeln('Could not resolve the project directory. ' . $workingDirectory);
      return Command::FAILURE;
    }

    $workingDirectory = $resolvedWorkingDirectory;
    $output->writeln('Playing the game in the working directory: ' . $workingDirectory, OutputInterface::VERBOSITY_VERBOSE);
    $config = new AppConfig($input, $output, $workingDirectory);
    $configuredMainFile = $config->get('main');
    $mainFile = is_string($configuredMainFile) && trim($configuredMainFile) !== ''
      ? Path::join($workingDirectory, $configuredMainFile)
      : '';
    $autoloadFile = Path::join($workingDirectory, 'vendor', 'autoload.php');

    if ($mainFile === '' || ! is_file($mainFile)) {
      $output->writeln('The main file does not exist. ' . $mainFile);
      return Command::FAILURE;
    }

    if (! file_exists($autoloadFile)) {
      $output->writeln('Project dependencies are not installed yet. Run `composer install` in the project directory first.');
      $output->writeln([
        'Then try `ichiloto play` again.',
        'Project directory: ' . $workingDirectory,
      ], OutputInterface::VERBOSITY_VERBOSE);

      return Command::FAILURE;
    }

    $rendererOption = $input->getOption('renderer');

    if ($rendererOption !== null && ! is_string($rendererOption)) {
      $output->writeln('The renderer option must be a renderer ID.');
      return Command::INVALID;
    }

    try {
      $renderer = $this->rendererSelector->resolve(
        rendererOption: $rendererOption,
        gpuiAlias: (bool) $input->getOption('gpui-renderer'),
        canPrompt: $input->isInteractive() && $this->terminalInteractivity->supportsPrompts(),
      );
    } catch (InvalidArgumentException $exception) {
      $output->writeln($exception->getMessage());
      return Command::INVALID;
    }

    $errorLogFile = $this->prepareErrorLogFile($workingDirectory);

    if (! (bool) $input->getOption('no-tmux') && $this->shouldLaunchInTmux()) {
      return $this->launchInTmux($workingDirectory, $mainFile, $errorLogFile, $renderer->id);
    }

    $originalWorkingDirectory = getcwd();

    if (! @chdir($workingDirectory)) {
      $output->writeln('Could not switch to the project directory. ' . $workingDirectory);
      return Command::FAILURE;
    }

    try {
      $command = $this->launchCommandBuilder->buildGameCommand(
        mainFile: $mainFile,
        errorLogFile: $errorLogFile,
        rendererId: $renderer->id,
      );

      $resultCode = Command::FAILURE;

      if (false === passthru($command, $resultCode) ) {
        $output->writeln("An error occurred while playing the game.");
        $output->writeln([
          "Please make sure the main file is executable and contains the game logic.",
          "Result Code: $resultCode"
        ], OutputInterface::VERBOSITY_VERBOSE);
        return Command::FAILURE;
      }
    } finally {
      if ($originalWorkingDirectory !== false) {
        @chdir($originalWorkingDirectory);
      }
    }

    if ($resultCode !== 0) {
      $output->writeln('The game exited unexpectedly. Check the error log for details: ' . $errorLogFile);
    }

    return $resultCode === 0 ? Command::SUCCESS : Command::FAILURE;
  }

  /**
   * Determines whether gameplay should use a tmux session.
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
   * Launches the game inside a tmux session.
   *
   * @param string $workingDirectory The project directory.
   * @param string $mainFile The resolved main PHP file.
   * @return int
   */
  private function launchInTmux(
    string $workingDirectory,
    string $mainFile,
    string $errorLogFile,
    string $rendererId,
  ): int
  {
    $sessionName = 'ichiloto-play-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', basename($workingDirectory));
    $gameCommand = $this->launchCommandBuilder->buildGameCommand($mainFile, $errorLogFile, $rendererId);
    $launchCommand = $this->launchCommandBuilder->buildCrashPreservingCommand($gameCommand, 'Ichiloto game');

    if (! $this->tmuxSessionExists($sessionName)) {
      passthru($this->launchCommandBuilder->buildTmuxNewSessionCommand(
        sessionName: $sessionName,
        workingDirectory: $workingDirectory,
        launchCommand: $launchCommand,
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
   * Ensures the project's error log file can receive captured stderr output.
   *
   * @param string $workingDirectory The project root.
   * @return string The resolved error log filename.
   */
  private function prepareErrorLogFile(string $workingDirectory): string
  {
    $logDirectory = Path::join($workingDirectory, 'logs');

    if (! is_dir($logDirectory)) {
      mkdir($logDirectory, 0777, true);
    }

    $errorLogFile = Path::join($logDirectory, 'error.log');

    if (! file_exists($errorLogFile)) {
      touch($errorLogFile);
    }

    return $errorLogFile;
  }
}
