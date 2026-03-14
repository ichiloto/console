<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
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
      ->addOption('directory', 'd', InputArgument::OPTIONAL, 'The directory to save the map.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    // Generate a map
    $output->writeln("Generating a map.", OutputInterface::VERBOSITY_VERBOSE);

    $name = strtokebab($input->getArgument('name'));
    $outputDirectory = $input->getOption('directory') ?? getcwd() ?: '.';
    $filename = Path::join($outputDirectory, $name . '.json');

    $defaultNamespace = 'Amasiye\Ichiloto\Maps';
    $namespace = text('Enter the namespace of the map:', $defaultNamespace, $defaultNamespace);

    $description = textarea('Enter the description of the map:');

    $content = <<<PHP
<?php

$namespace;

return [
  'name' => '$name',
  'region' => '',
  '$description' => '$description',
  'position' => ['x' => 0, 'y' => 0],
  'player' => ['x' => 0, 'y' => 0, 'sprite' => ['']],
  'texture_map' => [],
  'collision_map' => [],
  'texture_offset' => ['x' => 0, 'y' => 0],
  'collision_offset' => ['x' => 0, 'y' => 0],
  'triggers' => ['exit' => []],
];
PHP;

    $output->writeln("Saving the map to: $filename", OutputInterface::VERBOSITY_VERBOSE);
    $output->writeln($content);

    return Command::SUCCESS;
  }
}