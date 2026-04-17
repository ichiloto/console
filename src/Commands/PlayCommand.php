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

    if (! file_exists($mainFile)) {
      $output->writeln('The main file does not exist. ' . $mainFile);
      return Command::FAILURE;
    }

    if (! (bool) $input->getOption('no-tmux') && $this->shouldLaunchInTmux()) {
      return $this->launchInTmux($workingDirectory, $mainFile);
    }

    $command = escapeshellarg(PHP_BINARY) . ' ' . escapeshellarg($mainFile);

    if (false === passthru($command, $resultCode) ) {
      $output->writeln("An error occurred while playing the game.");
      $output->writeln([
        "Please make sure the main file is executable and contains the game logic.",
        "Result Code: $resultCode"
      ], OutputInterface::VERBOSITY_VERBOSE);
      return Command::FAILURE;
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
  private function launchInTmux(string $workingDirectory, string $mainFile): int
  {
    $sessionName = 'ichiloto-play-' . preg_replace('/[^A-Za-z0-9_-]+/', '-', basename($workingDirectory));
    $gameCommand = sprintf('%s %s', escapeshellcmd(PHP_BINARY), escapeshellarg($mainFile));
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
}
