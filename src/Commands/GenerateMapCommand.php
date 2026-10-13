<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\MapScaffolder;
use Ichiloto\Console\Util\Path;
use Ichiloto\Engine\Rendering\Tilesets\Tileset;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\select;
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
      ->addOption('kind', null, InputOption::VALUE_REQUIRED, "The map's kind: one of the project's tilesets in assets/Data/Tilesets, by id.")
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

    // A map is born with its kind, the tileset its tiles and pieces come
    // from. A project without tilesets has none to give it.
    $kinds = $this->loadKinds(dirname((string) $mapsRoot));
    $kind = $input->getOption('kind');

    if (is_string($kind) && ! isset($kinds[$kind])) {
      $output->writeln($kinds === []
        ? "<error>The project has no tilesets in assets/" . Tileset::DIRECTORY . ", so a map cannot be of kind {$kind}.</error>"
        : "<error>{$kind} is not one of the project's kinds: " . implode(', ', array_keys($kinds)) . '.</error>');

      return Command::FAILURE;
    }

    if (! is_string($kind) && $kinds !== []) {
      if (! $input->isInteractive()) {
        $output->writeln("<error>Name the map's kind with --kind: " . implode(', ', array_keys($kinds)) . '.</error>');

        return Command::FAILURE;
      }

      $kind = (string) select('Choose the kind of map:', $kinds);
    }

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
          ...(is_string($kind) ? ['tileset' => $kind] : []),
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
    $output->writeln(is_string($kind)
      ? "Kind: {$kinds[$kind]}"
      : 'Kind: none, since the project has no tilesets in assets/' . Tileset::DIRECTORY);

    foreach ($paths as $path) {
      $output->writeln("  {$path}", OutputInterface::VERBOSITY_VERBOSE);
    }

    return Command::SUCCESS;
  }

  /**
   * The name of every tileset in the asset root that loads, by id. One that
   * cannot load is not offered; `ichiloto validate` reports it.
   *
   * @return array<string, string>
   */
  private function loadKinds(string $assetRoot): array
  {
    $kinds = [];

    foreach (glob(Path::join($assetRoot, Tileset::DIRECTORY, '*.php')) ?: [] as $file) {
      $id = basename($file, '.php');

      try {
        $kinds[$id] = Tileset::load($assetRoot, $id)->name;
      } catch (\Throwable) {
        continue;
      }
    }

    return $kinds;
  }
}
