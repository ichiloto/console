<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\AppConfig;
use Ichiloto\Console\Util\Path;
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
  /**
   * @inheritDoc
   */
  public function configure(): void
  {
    $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The directory to save the game data.');
    $this->addOption('no-tmux', null, InputOption::VALUE_NONE, 'Launch directly without creating or reusing a tmux session.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $workingDirectory = $input->getOption('directory') ?? getcwd() ?: '.';

    if (is_not_valid_working_dir($workingDirectory)) {
      $output->writeln('The working directory is not valid. ' . $workingDirectory);
      $output->writeln('Please make sure the working directory exists and contains the ichiloto.json file.', OutputInterface::VERBOSITY_VERBOSE);
      return Command::FAILURE;
    }

    $output->writeln('Playing the game in the working directory: ' . $workingDirectory, OutputInterface::VERBOSITY_VERBOSE);
    $config = new AppConfig($input, $output, $workingDirectory);
    $mainFile = Path::join($workingDirectory, $config->get('main'));
    $autoloadFile = Path::join($workingDirectory, 'vendor', 'autoload.php');
    $errorLogFile = $this->prepareErrorLogFile($workingDirectory);

    if (! file_exists($mainFile)) {
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

    if (! (bool) $input->getOption('no-tmux') && $this->shouldLaunchInTmux()) {
      return $this->launchInTmux($workingDirectory, $mainFile, $errorLogFile);
    }

    $originalWorkingDirectory = getcwd();

    if (! @chdir($workingDirectory)) {
      $output->writeln('Could not switch to the project directory. ' . $workingDirectory);
      return Command::FAILURE;
    }

    try {
      $command = $this->buildPhpLaunchCommand(
        mainFile: basename($mainFile),
        errorLogFile: $errorLogFile,
      );

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
  private function launchInTmux(string $workingDirectory, string $mainFile, string $errorLogFile): int
  {
    $sessionName = 'ichiloto-play-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', basename($workingDirectory));
    $gameCommand = $this->buildPhpLaunchCommand($mainFile, $errorLogFile);
    $launchCommand = $this->buildCrashPreservingCommand($gameCommand, 'Ichiloto game');

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
   * Builds the PHP process command used to launch the game's main file.
   *
   * Fatal output is suppressed from the terminal and appended to the
   * project's error log instead, so crash details remain available even when
   * a legacy runtime clears the screen before the shell redraws.
   *
   * @param string $mainFile The main file to execute.
   * @param string $errorLogFile The error log file to append to.
   * @return string
   */
  private function buildPhpLaunchCommand(string $mainFile, string $errorLogFile): string
  {
    return sprintf(
      '%s -d display_errors=0 -d display_startup_errors=0 -d log_errors=1 -d error_log=%s %s 2>> %s',
      escapeshellcmd(PHP_BINARY),
      escapeshellarg($errorLogFile),
      escapeshellarg($mainFile),
      escapeshellarg($errorLogFile),
    );
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
