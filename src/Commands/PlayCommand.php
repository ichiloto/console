<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\AppConfig;
use Ichiloto\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\Input;
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
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $workingDirectory = $input->getOption('directory') ?? getcwd() ?: '.';
    $configPath = Path::join($workingDirectory, 'ichiloto.json');

    // Play the game
    if ( is_not_valid_working_dir($workingDirectory) ) {
      $output->writeln("The working directory is not valid. " . $workingDirectory);
      $output->writeln("Please make sure the working directory exists and contains the ichiloto.json file.", OutputInterface::VERBOSITY_VERBOSE);
      return Command::FAILURE;
    }

    $output->writeln("Playing the game in the working directory: " . $workingDirectory, OutputInterface::VERBOSITY_VERBOSE);
    $config = new AppConfig($input, $output, $workingDirectory);

    $mainFile = Path::join($workingDirectory, $config->get('main'));

    if ( ! file_exists($mainFile) ) {
      $output->writeln("The main file does not exist. " . $mainFile);
      return Command::FAILURE;
    }

    if (false === passthru("php $mainFile", $resultCode) ) {
      $output->writeln("An error occurred while playing the game.");
      $output->writeln([
        "Please make sure the main file is executable and contains the game logic.",
        "Result Code: $resultCode"
      ], OutputInterface::VERBOSITY_VERBOSE);
      return Command::FAILURE;
    }

    return Command::SUCCESS;
  }
}