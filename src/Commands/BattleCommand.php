<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Scenes\Arena\ArenaScene;
use Ichiloto\Engine\Battle\Simulation\SimulationReport;
use Ichiloto\Engine\Entities\Character;
use Ichiloto\Engine\Entities\EquipmentSlot;
use Ichiloto\Engine\Entities\Interfaces\CharacterInterface;
use Ichiloto\Engine\Entities\Inventory\InventoryItem;
use Ichiloto\Engine\Entities\Party;
use Ichiloto\Engine\Entities\Troop;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Ichiloto\Engine\Util\Config\ProjectConfig;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Symfony\Component\Console\Terminal;
use Throwable;

#[AsCommand(
  name: 'battle',
  description: 'Play a battle from the arena, or simulate one to balance it.',
)]
class BattleCommand extends Command
{
  public function configure(): void
  {
    $this
      ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
      ->addOption('troop', 't', InputOption::VALUE_REQUIRED, 'The troop to fight. Without it the arena opens on the list.')
      ->addOption('runs', 'r', InputOption::VALUE_REQUIRED, 'Simulate this many battles instead of playing one.')
      ->addOption('turn-limit', 'l', InputOption::VALUE_REQUIRED, 'How long a simulated battle may run before it counts as a slog.', '50');
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

    // Playing the fight is the point; simulating it is what you do once you
    // have played it and want to know what it does a hundred times over.
    if ($input->getOption('runs') === null) {
      return $this->play($workingDirectory, $input->getOption('troop'), $output);
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
    $this->line($output, sprintf(
      '  %s against %d %s, %d runs each.',
      $this->describeParty($party),
      count($troops),
      count($troops) === 1 ? 'troop' : 'troops',
      $runs
    ), 'comment');
    $output->writeln('');

    // What is fighting, before what happened to it: a loadout, an earned
    // modifier or a stat sitting on its cap explains a result that otherwise
    // looks like a balance problem.
    $this->reportParty($output, $party);

    foreach ($troops as $troop) {
      $this->report($output, $simulator->simulate($party, $troop, $runs));
      $this->reportSampleHits($output, $party, $troop, $simulator);
    }

    $this->reportLimits($output);

    return Command::SUCCESS;
  }

  /**
   * Returns how wide a line may be.
   *
   * A report read in a narrow terminal must still be a report, so every line
   * this command prints is measured against the terminal it is printing to
   * and cut rather than wrapped into rubble.
   *
   * @return int The width, in columns.
   */
  protected function width(): int
  {
    return max(40, new Terminal()->getWidth());
  }

  /**
   * Prints one line, cut to the terminal's width.
   *
   * The text is measured before any styling is applied, because a colour tag
   * costs columns on nobody's screen.
   *
   * @param OutputInterface $output Where to print.
   * @param string $text The line, unstyled.
   * @param string|null $style The style to wrap it in, if any.
   * @return void
   */
  protected function line(OutputInterface $output, string $text, ?string $style = null): void
  {
    $text = self::cutToColumns($text, $this->width());

    $output->writeln($style === null ? $text : sprintf('<%s>%s</>', $style, $text));
  }

  /**
   * Returns how many terminal columns a string occupies.
   *
   * A character is not a column: a CJK glyph and most pictographs take two,
   * a combining mark takes none, and a joined emoji sequence is one glyph
   * however many code points it is written with. The engine already owns
   * that contract in `TerminalText`, and it is the one the game draws its
   * own screens with, so this asks it rather than keeping a second opinion
   * about how wide a glyph is.
   *
   * @param string $text The text.
   * @return int The columns it occupies.
   */
  public static function columnsOf(string $text): int
  {
    // Symfony's own tags cost nothing on screen, so they cost nothing here.
    return TerminalText::displayWidth(self::withoutTags($text));
  }

  /**
   * Cuts a string to a number of terminal columns.
   *
   * @param string $text The text.
   * @param int $columns The columns available.
   * @return string The text, no wider than that.
   */
  public static function cutToColumns(string $text, int $columns): string
  {
    if (self::columnsOf($text) <= $columns) {
      return $text;
    }

    // One column is held back for the ellipsis that says it was cut, and
    // the cut itself lands on a symbol boundary rather than inside a glyph.
    return TerminalText::truncateToWidth(self::withoutTags($text), max(0, $columns - 1)) . '…';
  }

  /**
   * Returns text with Symfony's formatting tags removed.
   *
   * @param string $text The text.
   * @return string The visible text.
   */
  private static function withoutTags(string $text): string
  {
    return (string) preg_replace('/<\/?([a-zA-Z][^<>]*)?>/', '', $text);
  }

  /**
   * Prints what each party member brings to the fight.
   *
   * Every number here is the engine's own projection of the character, read
   * through `Character::resolveStats()`: this command resolves nothing, caps
   * nothing and scores nothing itself.
   *
   * @param OutputInterface $output Where to print.
   * @param Party $party The party.
   * @return void
   */
  protected function reportParty(OutputInterface $output, Party $party): void
  {
    $this->line($output, '  Party as fought', 'options=bold');

    foreach ($party->battlers->toArray() as $member) {
      if (! $member instanceof Character) {
        continue;
      }

      $this->line($output, sprintf('    %s  level %d', $member->name, $member->level), 'comment');
      $this->reportLoadout($output, $member);
      $this->reportPermanentGrowth($output, $member);
      $this->reportStatLayers($output, $member);
    }

    $output->writeln('');
  }

  /**
   * Prints what a battler is holding, by the identity a save records.
   *
   * A display name is what a designer recognises and a stable id is what the
   * runtime resolves, so both are printed: they are the same thing only
   * until something is renamed.
   *
   * @param OutputInterface $output Where to print.
   * @param Character $member The battler.
   * @return void
   */
  protected function reportLoadout(OutputInterface $output, Character $member): void
  {
    $worn = [];

    foreach ($member->equipment as $slot) {
      if (! $slot instanceof EquipmentSlot) {
        continue;
      }

      if ($slot->equipment instanceof InventoryItem) {
        $worn[] = sprintf(
          '      %-10s %s (%s)',
          $slot->semanticSlot->value,
          $slot->equipment->name,
          $slot->equipment->id,
        );
      }
    }

    // Nothing worn is one fact, not five. It is also the ordinary case for a
    // simulation built from project data, because no project file fills a
    // slot at this engine head -- the game equips in play.
    foreach ($worn === [] ? ['      every slot empty'] : $worn as $entry) {
      $this->line($output, $entry, 'fg=gray');
    }
  }

  /**
   * Prints the permanent growth a battler carries, with its provenance.
   *
   * @param OutputInterface $output Where to print.
   * @param Character $member The battler.
   * @return void
   */
  protected function reportPermanentGrowth(OutputInterface $output, Character $member): void
  {
    foreach ($member->permanentGrowth->all() as $modifier) {
      $this->line($output, sprintf(
        '      growth     %s  %+d %s  from %s %s',
        $modifier->id,
        $modifier->amount,
        $modifier->stat->value,
        $modifier->sourceType,
        $modifier->sourceId,
      ), 'fg=gray');
    }
  }

  /**
   * Prints what each stat comes to, layer by layer, with what the cap does.
   *
   * Every canonical stat is printed, whether or not a layer moved it: the
   * cap and the room left under it are as much part of reading a fight as
   * the layers are, and a stat sitting on its cap is invisible until it is
   * printed.
   *
   * @param OutputInterface $output Where to print.
   * @param Character $member The battler.
   * @return void
   */
  protected function reportStatLayers(OutputInterface $output, Character $member): void
  {
    foreach ($member->resolveStats() as $key => $resolution) {
      $contributions = [];

      foreach ([
        'nature' => $resolution->actorNatural,
        'growth' => $resolution->permanent,
        'gear' => $resolution->equipment,
        'battle' => $resolution->temporary,
      ] as $noun => $amount) {
        if ($amount !== 0) {
          $contributions[] = sprintf('%+d %s', $amount, $noun);
        }
      }

      $this->line($output, sprintf(
        '      %-13s%d · %d natural%s · %s',
        $key,
        $resolution->effectiveValue,
        $resolution->natural,
        $contributions === [] ? '' : ', ' . implode(', ', $contributions),
        $resolution->capLoss > 0
          ? sprintf('%d lost to the %d cap', $resolution->capLoss, $resolution->cap)
          : sprintf('%d to the %d cap', $resolution->remainingHeadroom, $resolution->cap),
      ), 'fg=gray');
    }
  }

  /**
   * Prints one seeded attack per party member, hit by hit.
   *
   * The run aggregates say nothing about whether an attack landed, crit, was
   * guarded, or met an affinity, because the report does not carry those.
   * The engine does expose them, per hit, through the simulator's own seeded
   * preview seam, so one deterministic attack is shown for what it is: a
   * single resolved action, not a rate across the run.
   *
   * @param OutputInterface $output Where to print.
   * @param Party $party The party.
   * @param Troop $troop The troop.
   * @param BattleSimulator $simulator The simulator.
   * @return void
   */
  protected function reportSampleHits(
    OutputInterface $output,
    Party $party,
    Troop $troop,
    BattleSimulator $simulator
  ): void
  {
    $target = null;

    foreach ($troop->members->toArray() as $member) {
      if ($member instanceof CharacterInterface) {
        $target = $member;
        break;
      }
    }

    if (! $target instanceof CharacterInterface) {
      return;
    }

    $this->line($output, sprintf('    one seeded attack each on %s, not a rate across the run', $target->name), 'fg=gray');

    // Every preview starts from the state the last one started from, and
    // every battler is put back exactly as it was found. A report is a
    // reading of the project, not a rehearsal on it.
    $restore = self::mutableStateOf([$target, ...$party->battlers->toArray()]);
    $targetBaseline = self::mutableStateOf([$target])[spl_object_id($target)] ?? [];

    foreach ($party->battlers->toArray() as $attacker) {
      if (! $attacker instanceof CharacterInterface) {
        continue;
      }

      // The same starting target for each attacker, so the second is not
      // swinging at a battler the first already hurt.
      self::restoreMutableState([$target], [spl_object_id($target) => $targetBaseline]);

      foreach ($simulator->previewAttack($attacker, $target)->hits() as $hit) {
        $this->line($output, sprintf('    %-14s %s', $attacker->name, $this->describeHit($hit)), 'fg=gray');
      }
    }

    self::restoreMutableState([$target, ...$party->battlers->toArray()], $restore);

    $output->writeln('');
  }

  /**
   * Records everything a resolved attack can change about a battler.
   *
   * Health is the obvious part, but a resolver also writes states, stat
   * stages, guard flags and whatever feedback properties a battler's class
   * declares. Snapshotting the whole mutable surface is what makes the
   * difference between a report that reads the project and one that quietly
   * plays a round of combat on it.
   *
   * @param array<int, mixed> $battlers The battlers.
   * @return array<int, array<string, mixed>> The state, by object id.
   */
  public static function mutableStateOf(array $battlers): array
  {
    $state = [];

    foreach ($battlers as $battler) {
      if (! is_object($battler)) {
        continue;
      }

      $snapshot = [];

      foreach (self::realProperties($battler) as $name => $value) {
        if (is_object($value)) {
          // A value object the resolver writes through -- stats, and
          // anything shaped like it -- is recorded by what it holds, not by
          // which instance holds it.
          foreach (self::realProperties($value) as $inner => $innerValue) {
            if (! is_object($innerValue)) {
              $snapshot[$name . '.' . $inner] = $innerValue;
            }
          }

          continue;
        }

        $snapshot[$name] = $value;
      }

      $state[spl_object_id($battler)] = $snapshot;
    }

    return $state;
  }

  /**
   * Returns the properties an object actually stores.
   *
   * A property whose value is computed on every read holds no state, so it
   * cannot be changed and cannot be restored -- and comparing two reads of
   * one would report a difference where nothing happened.
   *
   * @param object $subject The object.
   * @return array<string, mixed> The stored values, by name.
   */
  private static function realProperties(object $subject): array
  {
    $values = [];

    foreach (new \ReflectionObject($subject)->getProperties() as $property) {
      if ($property->isStatic() || $property->isVirtual() || ! $property->isInitialized($subject)) {
        continue;
      }

      $values[$property->getName()] = $property->getValue($subject);
    }

    return $values;
  }

  /**
   * Puts every battler back exactly as it was found.
   *
   * @param array<int, mixed> $battlers The battlers.
   * @param array<int, array<string, mixed>> $state The recorded state.
   * @return void
   */
  public static function restoreMutableState(array $battlers, array $state): void
  {
    foreach ($battlers as $battler) {
      if (! is_object($battler)) {
        continue;
      }

      foreach ($state[spl_object_id($battler)] ?? [] as $name => $value) {
        $parts = explode('.', $name, 2);
        $target = $battler;

        if (count($parts) === 2) {
          $holder = self::realProperties($battler)[$parts[0]] ?? null;

          if (! is_object($holder)) {
            continue;
          }

          $target = $holder;
          $name = $parts[1];
        }

        $property = new \ReflectionObject($target)->hasProperty($name)
          ? new \ReflectionProperty($target, $name)
          : null;

        if ($property === null || $property->isVirtual() || $property->isReadOnly()) {
          continue;
        }

        $property->setValue($target, $value);
      }
    }
  }

  /**
   * Describes one resolved hit in the engine's own terms.
   *
   * Every value here is read off the engine's typed hit result. Nothing is
   * recomputed, and nothing is parsed back out of a message.
   *
   * @param CombatHitResult $hit The hit.
   * @return string The description.
   */
  protected function describeHit(CombatHitResult $hit): string
  {
    if (! $hit->hit) {
      return sprintf('missed (%s), %d%% to hit', $hit->missReason, $hit->hitChance);
    }

    $parts = [sprintf('%d damage', $hit->actualHpLost)];

    if ($hit->actualHpRestored > 0) {
      $parts[] = sprintf('%d restored', $hit->actualHpRestored);
    }

    if ($hit->critical) {
      $parts[] = sprintf('critical ×%.1f', $hit->criticalMultiplier);
    }

    if ($hit->guardApplied) {
      $parts[] = 'guarded';
    }

    if ($hit->elementalOutcome !== ElementalOutcome::NORMAL) {
      $parts[] = sprintf(
        '%s %s ×%.1f',
        $hit->element ?? 'element',
        $hit->elementalOutcome->value,
        $hit->elementalMultiplier,
      );
    }

    if ($hit->mitigationAmount > 0) {
      $parts[] = sprintf('%d mitigated (%d%%)', $hit->mitigationAmount, round($hit->mitigationRate * 100));
    }

    if ($hit->overkill > 0) {
      $parts[] = sprintf('%d overkill', $hit->overkill);
    }

    return implode(', ', $parts);
  }

  /**
   * Prints what this report cannot say, and what would let it.
   *
   * Guard, misses, criticals and elemental outcomes are typed per hit but
   * are not aggregated across a run, so no honest rate can be printed for
   * them. Naming the exact values that are missing is more use than a number
   * inferred from something else.
   *
   * @param OutputInterface $output Where to print.
   * @return void
   */
  protected function reportLimits(OutputInterface $output): void
  {
    $this->line($output, '  What this report cannot say', 'options=bold');

    foreach ([
      'Simulated battlers attack. They do not guard, cast, or use items, so no',
      'guarded, cast or item outcome appears in a run at all.',
      '',
      'Miss, critical, guard and Weak/Resist/Null/Absorb rates across a run are',
      'not reported. They are typed per hit on CombatHitResult, which the',
      'simulator exposes only through previewAttack(); SimulationReport carries',
      'no aggregate of them. Reporting them would need SimulationReport to',
      'carry, per battler: miss and critical counts, guarded-hit counts, and',
      'counts per ElementalOutcome case. Until it does, the single seeded',
      'attack above is all this command can honestly show.',
    ] as $sentence) {
      $this->line($output, $sentence === '' ? '' : '    ' . $sentence, 'fg=gray');
    }

    $output->writeln('');
  }

  /**
   * Opens the arena and hands the terminal to the game.
   *
   * @param string $workingDirectory The project directory.
   * @param string|null $troop The troop to drop straight into, if any.
   * @param OutputInterface $output Where to report a failure.
   * @return int The exit code.
   */
  protected function play(string $workingDirectory, ?string $troop, OutputInterface $output): int
  {
    $previousDirectory = getcwd();

    if (! @chdir($workingDirectory)) {
      $output->writeln('<error>Could not enter the project directory.</error>');

      return Command::FAILURE;
    }

    try {
      // The arena opens on its list of troops; naming one skips straight to
      // that fight.
      new Game(
        $this->projectName($workingDirectory),
        options: [
          'starting_scene' => ArenaScene::class,
          'arena_troop' => $troop ?? '',
        ]
      )->run();
    } catch (Throwable $throwable) {
      @chdir($previousDirectory ?: '.');
      $output->writeln('<error>The arena could not start: ' . $throwable->getMessage() . '</error>');

      return Command::FAILURE;
    }

    @chdir($previousDirectory ?: '.');

    return Command::SUCCESS;
  }

  /**
   * Reads the project's name.
   *
   * @param string $workingDirectory The project directory.
   * @return string The name.
   */
  protected function projectName(string $workingDirectory): string
  {
    $manifest = rtrim($workingDirectory, DIRECTORY_SEPARATOR) . DIRECTORY_SEPARATOR . 'ichiloto.json';

    if (is_file($manifest)) {
      $data = json_decode((string) file_get_contents($manifest), true);

      if (is_array($data) && isset($data['name']) && is_string($data['name'])) {
        return $data['name'];
      }
    }

    return basename(rtrim($workingDirectory, DIRECTORY_SEPARATOR));
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

    // Two styles on one line, so the troop name is cut to what is left
    // after the verdict -- measured in the columns a glyph actually
    // occupies, like every other line this command prints.
    $verdict = $report->verdict();
    $room = $this->width() - self::columnsOf($verdict) - 4;
    $troop = self::cutToColumns($report->troop, max(1, $room));

    $output->writeln(sprintf('  <options=bold>%s</>  <fg=%s>%s</>', $troop, $colour, $verdict));
    // Raw counts beside the shares: a percentage of five runs and a
    // percentage of five hundred read the same and mean very different
    // things.
    $this->line($output, sprintf(
      '    %d runs: %d won (%d%%), %d lost (%d%%), %d unfinished (%d%%)',
      $report->runs,
      $report->victories,
      round($winRate * 100),
      $report->defeats,
      round($report->defeats / $report->runs * 100),
      $report->stalemates,
      round($report->stalemates / $report->runs * 100),
    ));
    $this->line($output, sprintf(
      '    %.1f turns   %d%% party health left on a win',
      $report->averageTurns,
      round($report->averageHpRemaining * 100)
    ));

    // Everyone the run has a number for, not only those who dealt damage:
    // a healer who dealt none still belongs in the report.
    $names = array_values(array_unique([
      ...array_keys($report->damageDealt),
      ...array_keys($report->healing),
      ...array_keys($report->hpLost),
      ...array_keys($report->mitigation),
      ...array_keys($report->deaths),
    ]));

    foreach ($names as $name) {
      $parts = [];

      foreach ([
        'damage' => $report->damageDealt[$name] ?? 0.0,
        'healing' => $report->healing[$name] ?? 0.0,
        'HP lost' => $report->hpLost[$name] ?? 0.0,
        'mitigated' => $report->mitigation[$name] ?? 0.0,
      ] as $noun => $amount) {
        if (round((float) $amount, 1) > 0) {
          $parts[] = sprintf('%.1f %s', $amount, $noun);
        }
      }

      $fell = $report->deaths[$name] ?? 0;

      if ($fell > 0) {
        $parts[] = sprintf('fell in %d%%', round($fell / $report->runs * 100));
      }

      $this->line(
        $output,
        sprintf('    %-14s %s', $name, $parts === [] ? 'nothing recorded' : implode(', ', $parts)),
        'fg=gray'
      );
    }

    // The seed is what makes a run repeatable, and the simulation's own
    // limits are part of reading its numbers honestly.
    $this->line($output, sprintf('    seed %d', $report->seed), 'fg=gray');
    $this->line($output, '    Simulated battlers attack; they do not guard, cast, or use items.', 'fg=gray');
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
