<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Util\Path;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\textarea;

#[AsCommand(
  name: 'generate:actor',
  description: 'Generate an actor.',
)]
class GenerateActorCommand extends Command
{
  const string DEFAULT_ACTOR_NAME = 'Hero';

  public function configure(): void
  {
    $this
      ->addArgument('name', InputArgument::REQUIRED, 'The name of the actor.')
      ->addOption('force', 'f', InputOption::VALUE_NONE, 'Overwrite the actor file if it already exists.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $actorName = $input->getArgument('name') ?: self::DEFAULT_ACTOR_NAME;
    $actorDescription = textarea('Enter the description of the actor:', "e.g. A seasoned mage, master of elemental forces and arcane knowledge.");

    $exportedName = var_export($actorName, true);
    $exportedDescription = var_export($actorDescription, true);

    $actorFileContent = <<<PHP
<?php

use Ichiloto\Engine\Entities\Character;

return [
  'class' => Character::class,
  'data' => [
    'name' => $exportedName,
    'description' => $exportedDescription,
    'level' => 1,
    'currentExp' => 0,
    'stats' => [
      'currentHp' => 100,
      'currentMp' => 50,
      'attack' => 10,
      'defence' => 5,
      'magicAttack' => 5,
      'magicDefence' => 5,
      'grace' => 5,
      'speed' => 5,
      'evasion' => 5,
      'accuracy' => 5,
      'critical' => 5,
    ],
  ]
];

PHP;

    $filename = Path::join(getcwd() ?: '.', 'assets', 'Data', 'Actors', strtopascal($actorName) . '.php');

    if (file_exists($filename) && ! $input->getOption('force')) {
      $output->writeln("<error>File already exists: $filename (use --force to overwrite)</error>");
      return Command::FAILURE;
    }

    $directory = dirname($filename);

    if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
      $output->writeln("<error>Could not create directory: $directory</error>");
      return Command::FAILURE;
    }

    if (false === file_put_contents($filename, $actorFileContent)) {
      $output->writeln("<error>Could not write to file: $filename</error>");
      return Command::FAILURE;
    }

    $output->writeln("Created: $filename");

    return Command::SUCCESS;
  }
}
