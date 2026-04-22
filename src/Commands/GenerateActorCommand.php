<?php

namespace Ichiloto\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use function Laravel\Prompts\text;
use function Laravel\Prompts\textarea;

#[AsCommand(
  name: 'generate:actor',
  description: 'Generate an actor.',
)]
class GenerateActorCommand extends Command
{
  const string DEFAULT_ACTOR_NAME = 'Hero';

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $defaultName = self::DEFAULT_ACTOR_NAME;
    $actorName = $input->getArgument('name') ?? text('Enter the name of the actor:', "e.g. $defaultName", $defaultName);
    $actorDescription = textarea('Enter the description of the actor:', "e.g. A seasoned mage, master of elemental forces and arcane knowledge.");

    $actorFileContent = <<<PHP
<?php

use Ichiloto\Engine\Entities\Character;

return [
  'class' => Character::class,
  'data' => [
    'name' => '$actorName',
    'description' => '$actorDescription',
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

    return Command::SUCCESS;
  }
}