<?php

namespace Ichiloto\Console\Commands;

use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'battle',
    description: 'Start a battle.',
)]
class BattleCommand extends Command
{
  public function configure(): void
  {
    $this
      ->addOption('party', 'p', InputOption::VALUE_REQUIRED, 'The party to battle with.')
      ->addOption('troop', 't', InputOption::VALUE_REQUIRED, 'The troop to battle against.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    // TODO: Implement the battle logic.
    $party = $input->getOption('party') ?? throw new RuntimeException('The party is required.');
    $troop = $input->getOption('troop') ?? throw new RuntimeException('The troop is required.');

    $output->writeln("To be implemented: Battle between party {$party} and troop {$troop}.");

    return Command::SUCCESS;
  }
}