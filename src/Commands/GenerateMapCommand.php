<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Util\Path;
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
      ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The directory to save the map.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the map file if it already exists.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    // Generate a map
    $output->writeln("Generating a map.", OutputInterface::VERBOSITY_VERBOSE);

    $name = strtokebab($input->getArgument('name'));
    $outputDirectory = $input->getOption('directory') ?? getcwd() ?: '.';
    $filename = Path::join($outputDirectory, $name . '.php');

    $defaultNamespace = 'Amasiye\Ichiloto\Maps';
    $namespace = text('Enter the namespace of the map:', $defaultNamespace, $defaultNamespace);

    $description = textarea('Enter the description of the map:');

    $exportedName = var_export($name, true);
    $exportedDescription = var_export($description, true);

    $content = <<<PHP
<?php

namespace {$namespace};

return [
  'name' => $exportedName,
  'region' => '',
  'description' => $exportedDescription,
  'position' => ['x' => 0, 'y' => 0],
  'player' => ['x' => 0, 'y' => 0, 'sprite' => ['']],
  'texture_map' => [],
  'collision_map' => [],
  'texture_offset' => ['x' => 0, 'y' => 0],
  'collision_offset' => ['x' => 0, 'y' => 0],
  'triggers' => ['exit' => []],
];

PHP;

    if (file_exists($filename) && ! $input->getOption('force')) {
      $output->writeln("<error>File already exists: $filename (use --force to overwrite)</error>");
      return Command::FAILURE;
    }

    $directory = dirname($filename);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
      $output->writeln("<error>Could not create directory: $directory</error>");
      return Command::FAILURE;
    }

    $output->writeln("Saving the map to: $filename", OutputInterface::VERBOSITY_VERBOSE);

    if (false === file_put_contents($filename, $content)) {
      $output->writeln("<error>Could not write to file: $filename</error>");
      return Command::FAILURE;
    }

    $output->writeln("Created: $filename");

    return Command::SUCCESS;
  }
}
