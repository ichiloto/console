<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\MapScaffolder;
use Ichiloto\Console\Util\Path;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

#[AsCommand(
  name: 'generate:map',
  description: 'Generate a map.',
)]
class GenerateMapCommand extends Command
{
  public function configure(): void
  {
    $this
      ->addArgument('name', InputArgument::REQUIRED, 'The name of the map.')
      ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The maps root directory. Defaults to <project>/assets/Maps.')
      ->addOption('region', null, InputOption::VALUE_REQUIRED, 'The map region.')
      ->addOption('description', null, InputOption::VALUE_REQUIRED, 'The map description.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite all files for an existing map.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $displayName = trim((string) $input->getArgument('name'));
    $mapId = trim((string) preg_replace('/[^a-z0-9]+/i', '-', strtokebab($displayName)), '-');

    if ($mapId === '') {
      $output->writeln('<error>The map name must contain at least one letter or number.</error>');
      return Command::FAILURE;
    }

    $mapsRoot = $input->getOption('directory') ?? Path::join(getcwd() ?: '.', 'assets', 'Maps');
    $mapDirectory = Path::join((string) $mapsRoot, $mapId);
    $region = $input->getOption('region');
    $description = $input->getOption('description');

    if (! is_string($region)) {
      $region = $input->isInteractive() ? text('Enter the region of the map:') : '';
    }

    if (! is_string($description)) {
      $description = $input->isInteractive() ? textarea('Enter the description of the map:') : '';
    }

    try {
      $paths = new MapScaffolder()->write(
        $mapDirectory,
        [
          'name' => $displayName,
          'region' => $region,
          'description' => $description,
          'triggers' => [],
          'events' => [],
        ],
        force: (bool) $input->getOption('force'),
      );
    } catch (RuntimeException $exception) {
      $output->writeln('<error>' . $exception->getMessage() . '</error>');

      return Command::FAILURE;
    }

    $output->writeln("Created map: {$mapDirectory}");

    foreach ($paths as $path) {
      $output->writeln("  {$path}", OutputInterface::VERBOSITY_VERBOSE);
    }

    return Command::SUCCESS;
  }
}
