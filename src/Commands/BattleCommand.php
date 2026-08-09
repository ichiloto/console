<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Battle\Simulation\SimulationReport;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
  name: 'battle',
  description: 'Fight a troop repeatedly and report how the fight balances.',
)]
class BattleCommand extends Command
{
  public function configure(): void
  {
    $this
      ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
      ->addOption('troop', 't', InputOption::VALUE_REQUIRED, 'The troop to fight. Every troop is fought when this is left out.')
      ->addOption('runs', 'r', InputOption::VALUE_REQUIRED, 'How many battles to fight per troop.', '200')
      ->addOption('turn-limit', 'l', InputOption::VALUE_REQUIRED, 'How long a battle may run before it counts as a slog.', '50');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $workingDirectory = $input->getOption('directory') ?? getcwd() ?: '.';

    if (is_not_valid_working_dir($workingDirectory)) {
      $output->writeln('<error>The working directory is not valid: ' . $workingDirectory . '</error>');

      return Command::FAILURE;
    }

    if (! load_engine_autoloader($workingDirectory)) {
      $output->writeln('<error>The engine could not be loaded for this project.</error>');

      return Command::FAILURE;
    }

    $previousDirectory = getcwd();

    // The engine's asset loading is relative to the working directory, so the
    // project's is borrowed for the read and given back afterwards.
    if (! @chdir($workingDirectory)) {
      $output->writeln('<error>Could not enter the project directory.</error>');

      return Command::FAILURE;
    }

    try {
      $this->registerProjectStores();
      $party = $this->loadParty();
      $troops = $this->loadTroops($input->getOption('troop'));
    } catch (Throwable $throwable) {
      $output->writeln('<error>The project could not be read: ' . $throwable->getMessage() . '</error>');
      @chdir($previousDirectory ?: '.');

      return Command::FAILURE;
    }

    @chdir($previousDirectory ?: '.');

    if ($troops === []) {
      $output->writeln('<error>No troops to fight.</error>');

      return Command::FAILURE;
    }

    $simulator = new BattleSimulator(max(1, (int) $input->getOption('turn-limit')));
    $runs = max(1, (int) $input->getOption('runs'));

    $output->writeln('');
    $output->writeln(sprintf(
      '  <comment>%s</comment> against %d %s, %d runs each.',
      $this->describeParty($party),
      count($troops),
      count($troops) === 1 ? 'troop' : 'troops',
      $runs
    ));
    $output->writeln('');

    foreach ($troops as $troop) {
      $this->report($output, $simulator->simulate($party, $troop, $runs));
    }

    return Command::SUCCESS;
  }

  /**
   * Prints one troop's result.
   *
   * @param OutputInterface $output Where to print.
   * @param SimulationReport $report What happened.
   * @return void
   */
  protected function report(OutputInterface $output, SimulationReport $report): void
  {
    $winRate = $report->winRate();
    $colour = match (true) {
      $winRate >= 0.9 => 'green',
      $winRate >= 0.5 => 'yellow',
      default => 'red',
    };

    $output->writeln(sprintf('  <options=bold>%s</>  <fg=%s>%s</>', $report->troop, $colour, $report->verdict()));
    $output->writeln(sprintf(
      '    %d%% won, %d%% lost, %d%% unfinished   %.1f turns   %d%% party health left on a win',
      round($winRate * 100),
      round($report->defeats / $report->runs * 100),
      round($report->stalemates / $report->runs * 100),
      $report->averageTurns,
      round($report->averageHpRemaining * 100)
    ));

    foreach ($report->damageDealt as $name => $damage) {
      $fell = $report->deaths[$name] ?? 0;
      $line = sprintf('    %-14s %6.1f damage per battle', $name, $damage);

      if ($fell > 0) {
        $line .= sprintf(', fell in %d%% of them', round($fell / $report->runs * 100));
      }

      $output->writeln("<fg=gray>{$line}</>");
    }

    $output->writeln('');
  }

  /**
   * Registers the stores a project's data files read while loading.
   *
   * @return void
   */
  protected function registerProjectStores(): void
  {
    if (! ConfigStore::has(ProjectConfig::class)) {
      ConfigStore::put(ProjectConfig::class, new ProjectConfig());
    }

    if (! ConfigStore::has(ItemStore::class)) {
      ConfigStore::put(ItemStore::class, new ItemStore());
    }

    if (! ConfigStore::has(EnemyStore::class)) {
      ConfigStore::put(EnemyStore::class, new EnemyStore());
    }
  }

  /**
   * Builds the project's starting party.
   *
   * @return Party The party.
   */
  protected function loadParty(): Party
  {
    $system = asset('Data/system.php', true);
    $members = [];

    foreach ((array) ($system['startingParty'] ?? []) as $member) {
      $data = asset("Data/Actors/{$member}.php", true);

      if (is_array($data) && isset($data['data'])) {
        $members[] = $data['data'];
      }
    }

    if ($members === []) {
      throw new \RuntimeException('The project has no starting party.');
    }

    return Party::fromArray($members);
  }

  /**
   * Builds the troops to fight.
   *
   * @param string|null $wanted The troop asked for, or null for all of them.
   * @return Troop[] The troops.
   */
  protected function loadTroops(?string $wanted): array
  {
    $troops = [];

    foreach ((array) asset('Data/troops.php', true) as $data) {
      if (! is_array($data)) {
        continue;
      }

      $name = strval($data['name'] ?? '');

      if ($wanted !== null && strcasecmp($name, $wanted) !== 0) {
        continue;
      }

      $troops[] = Troop::fromArray($data);
    }

    if ($troops === [] && $wanted !== null) {
      throw new \RuntimeException(sprintf('No troop named "%s".', $wanted));
    }

    return $troops;
  }

  /**
   * Names the party being fought with.
   *
   * @param Party $party The party.
   * @return string The description.
   */
  protected function describeParty(Party $party): string
  {
    $names = array_map(
      static fn(object $member): string => $member->name,
      $party->battlers->toArray()
    );

    return implode(', ', $names);
  }
}
