<?php

namespace Ichiloto\Console\Commands;

use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;

#[AsCommand(
    name: 'play',
    description: 'Play the game.'
)]
class PlayCommand extends Command
{
  public function execute(InputInterface $input, OutputInterface $output): int
  {
    // Play the game
    return Command::SUCCESS;
  }
}