<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Battle\BattleMemberOption;
use Ichiloto\Console\Battle\ParticipantSnapshot;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Renderer\RendererSelector;
use Ichiloto\Console\Support\GameLaunchCommandBuilder;
use Ichiloto\Console\Support\RendererUpdateOffer;
use Ichiloto\Console\Support\SourceRendererUpdateChecker;
use Ichiloto\Console\Support\SourceRendererUpdater;
use Ichiloto\Console\Support\TerminalInteractivity;
use InvalidArgumentException;
use Ichiloto\Engine\Battle\BattleCommandType;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Battle\Resolution\CombatHitResult;
use Ichiloto\Engine\Battle\Resolution\ElementalOutcome;
use Ichiloto\Engine\Battle\Simulation\BattleSimulator;
use Ichiloto\Engine\Core\Game;
use Ichiloto\Engine\Cutscenes\Summons\SummonCutsceneLibrary;
use Ichiloto\Engine\Entities\Skills\SkillCatalog;
use Ichiloto\Engine\Scenes\Arena\ArenaScene;
use Ichiloto\Engine\Scenes\Arena\BattleTestMember;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
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
use Ichiloto\Engine\Util\Stores\ActorStore;
use Ichiloto\Engine\Util\Stores\EnemyStore;
use Ichiloto\Engine\Util\Stores\ItemStore;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
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
  private readonly RendererRegistry $rendererRegistry;

  private readonly RendererSelector $rendererSelector;

  private readonly TerminalInteractivity $terminalInteractivity;

  private readonly RendererUpdateOffer $rendererUpdateOffer;

  /**
   * @param (callable(string, array<string, string>): (int|string))|null $rendererUpdatePrompt
   */
  public function __construct(
    ?RendererRegistry $rendererRegistry = null,
    ?RendererSelector $rendererSelector = null,
    ?TerminalInteractivity $terminalInteractivity = null,
    ?SourceRendererUpdateChecker $rendererUpdateChecker = null,
    ?SourceRendererUpdater $rendererUpdater = null,
    ?callable $rendererUpdatePrompt = null,
  ) {
    $this->rendererRegistry = $rendererRegistry ?? new RendererRegistry();
    $this->rendererSelector = $rendererSelector ?? new RendererSelector($this->rendererRegistry);
    $this->terminalInteractivity = $terminalInteractivity ?? new TerminalInteractivity();
    $this->rendererUpdateOffer = new RendererUpdateOffer($rendererUpdateChecker, $rendererUpdater,
      $this->terminalInteractivity, $rendererUpdatePrompt);

    parent::__construct();
  }

  public function configure(): void
  {
    $this
      ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
      ->addOption('troop', 't', InputOption::VALUE_REQUIRED, 'The troop to fight. Without it the arena opens on the list.')
      ->addOption('runs', 'r', InputOption::VALUE_REQUIRED, 'Simulate this many battles instead of playing one.')
      ->addOption('turn-limit', 'l', InputOption::VALUE_REQUIRED, 'How long a simulated battle may run before it counts as a slog.', '50')
      ->addOption('member', 'm', InputOption::VALUE_REQUIRED | InputOption::VALUE_IS_ARRAY,
        sprintf('A party member, as Actor[:level][,Slot=item...][,Commands=a|b][,Skills=a|b][,Summons=a|b]; repeat for each, up to %d. Without it, the starting party.',
          class_exists(BattleTestSetup::class) ? BattleTestSetup::MAX_MEMBERS : 4))
      ->addOption('arena', 'a', InputOption::VALUE_REQUIRED,
        'The battle presentation\'s arena to fight in, by key, drawn by a graphical renderer. Without it, its default arena; the arena can also be chosen on the troop list.')
      ->addOption('renderer', null, InputOption::VALUE_REQUIRED,
        sprintf('Renderer to play the battle in (%s)', implode(', ', $this->rendererRegistry->ids())))
      ->addOption('gpui-renderer', null, InputOption::VALUE_NONE, 'Play the battle in the GPUI renderer.');
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

    if (! class_exists(BattleTestSetup::class)) {
      $output->writeln('<error>This project\'s engine has no battle test setup; update its engine to use ichiloto battle.</error>');

      return Command::FAILURE;
    }

    $playing = $input->getOption('runs') === null;

    // A simulation draws nothing, so a renderer is a mistake to point out.
    if (! $playing && ($input->getOption('renderer') !== null || (bool) $input->getOption('gpui-renderer'))) {
      $output->writeln('A renderer applies to playing a battle, not to simulating one with --runs.');

      return Command::INVALID;
    }

    $arena = $input->getOption('arena');

    if ($arena !== null && ! $playing) {
      $output->writeln('An arena applies to playing a battle, not to simulating one with --runs.');

      return Command::INVALID;
    }

    $previousDirectory = getcwd();

    // The engine's asset loading is relative to the working directory, so the
    // project's is borrowed for the read and given back afterwards.
    if (! @chdir($workingDirectory)) {
      $output->writeln('<error>Could not enter the project directory.</error>');

      return Command::FAILURE;
    }

    $party = null;
    $troops = [];

    try {
      $this->registerProjectStores();
      // One setup for both: the party played with and the party simulated.
      $setup = $this->createSetup((array) $input->getOption('member'));
      if ($arena !== null) {
        $setup = $this->chooseArena($setup, strval($arena));
      }
      if (! $playing) {
        $this->refuseUnsimulatedLoadouts($setup);
        $party = $setup->createParty($this->getActorStore(), $this->getItemStore());
        $troops = $this->loadTroops($input->getOption('troop'));
      }
    } catch (InvalidArgumentException $invalid) {
      $output->writeln('<error>' . OutputFormatter::escape($invalid->getMessage()) . '</error>');
      @chdir($previousDirectory ?: '.');

      return Command::INVALID;
    } catch (Throwable $throwable) {
      $output->writeln('<error>The project could not be read: ' . $throwable->getMessage() . '</error>');
      @chdir($previousDirectory ?: '.');

      return Command::FAILURE;
    }

    @chdir($previousDirectory ?: '.');

    // Playing the fight is the point; simulating it is what you do once you
    // have played it and want to know what it does a hundred times over.
    if ($playing) {
      $rendererOption = $input->getOption('renderer');
      if ($rendererOption !== null && ! is_string($rendererOption)) {
        $output->writeln('The renderer option must be a renderer ID.');

        return Command::INVALID;
      }
      try {
        $renderer = $this->rendererSelector->resolve(
          rendererOption: $rendererOption,
          gpuiAlias: (bool) $input->getOption('gpui-renderer'),
          canPrompt: $input->isInteractive() && $this->terminalInteractivity->supportsPrompts(),
        );
      } catch (InvalidArgumentException $exception) {
        $output->writeln($exception->getMessage());

        return Command::INVALID;
      }
      // Only a graphical renderer draws an arena; the terminal draws the battle its own way.
      if ($arena !== null && $renderer->id === 'terminal') {
        $output->writeln('An arena is drawn by a graphical renderer; the terminal renderer draws none. Choose one with --renderer.');

        return Command::INVALID;
      }
      $this->rendererUpdateOffer->offer($workingDirectory, $renderer->id, $input, $output);

      return $this->play($workingDirectory, $input->getOption('troop'), $renderer->id, $setup, $output);
    }

    if ($troops === [] || ! $party instanceof Party) {
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

    $this->renderReport($output, $party, $troops, $simulator, $runs);

    return Command::SUCCESS;
  }

  /**
   * Prints the whole report as a reading of the participants, not a
   * rehearsal on them.
   *
   * A simulation runs the engine's own attacks on the engine's own
   * battlers, and a resolved attack writes more than health: states,
   * stages, guarding, and the critical and elemental feedback it leaves on
   * a target. Every participant is therefore recorded whole before anything
   * runs, put back before each troop is simulated and before each attacker
   * previews -- so every section starts from the state the project loaded
   * -- and put back once more when the report ends, whether it ends in the
   * last line or in an exception.
   *
   * @param OutputInterface $output Where to print.
   * @param Party $party The party, as loaded.
   * @param Troop[] $troops The troops, as loaded.
   * @param BattleSimulator $simulator The simulator.
   * @param int $runs How many battles to simulate per troop.
   * @return void
   */
  protected function renderReport(
    OutputInterface $output,
    Party $party,
    array $troops,
    BattleSimulator $simulator,
    int $runs
  ): void
  {
    $snapshot = ParticipantSnapshot::capture($party, ...$troops);

    try {
      // What is fighting, before what happened to it: a loadout, an earned
      // modifier or a stat sitting on its cap explains a result that
      // otherwise looks like a balance problem.
      $this->reportParty($output, $party);

      foreach ($troops as $troop) {
        $snapshot->restore();
        $report = $simulator->simulate($party, $troop, $runs);
        // The aggregate is printed off the typed report; the participants
        // are back where they started before anything reads them again.
        $snapshot->restore();
        $this->report($output, $report);
        $this->reportSampleHits($output, $party, $troop, $simulator, $snapshot);
      }

      $this->reportLimits($output);
    } finally {
      $snapshot->restore();
    }
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
   * @param ParticipantSnapshot $snapshot Every participant as the project loaded it.
   * @return void
   */
  protected function reportSampleHits(
    OutputInterface $output,
    Party $party,
    Troop $troop,
    BattleSimulator $simulator,
    ParticipantSnapshot $snapshot
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

    foreach ($party->battlers->toArray() as $attacker) {
      if (! $attacker instanceof CharacterInterface) {
        continue;
      }

      // Every attacker swings at the target the project loaded, from the
      // state the project loaded it in -- not at a battler the last
      // preview already hurt, and not as a battler the simulation left
      // changed.
      $snapshot->restore();

      foreach ($simulator->previewAttack($attacker, $target)->hits() as $hit) {
        $this->line($output, sprintf('    %-14s %s', $attacker->name, $this->describeHit($hit)), 'fg=gray');
      }
    }

    $output->writeln('');
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
  protected function play(string $workingDirectory, ?string $troop, string $rendererId, BattleTestSetup $setup,
    OutputInterface $output): int
  {
    $previousDirectory = getcwd();

    if (! @chdir($workingDirectory)) {
      $output->writeln('<error>Could not enter the project directory.</error>');

      return Command::FAILURE;
    }

    // The engine reads the renderer the way `play` hands it to the game,
    // from the environment, here for the arena alone.
    $variable = GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE;
    $previousRenderer = getenv($variable);
    putenv("{$variable}={$rendererId}");

    try {
      $this->runArena($this->projectName($workingDirectory), $troop ?? '', $setup);
    } catch (Throwable $throwable) {
      $output->writeln('<error>The arena could not start: ' . $throwable->getMessage() . '</error>');

      return Command::FAILURE;
    } finally {
      putenv($previousRenderer === false ? $variable : "{$variable}={$previousRenderer}");
      @chdir($previousDirectory ?: '.');
    }

    return Command::SUCCESS;
  }

  private function getActorStore(): ActorStore
  {
    $store = ConfigStore::get(ActorStore::class);

    return $store instanceof ActorStore ? $store : throw new \RuntimeException('The project\'s actors could not be loaded.');
  }

  private function getItemStore(): ItemStore
  {
    $store = ConfigStore::get(ItemStore::class);

    return $store instanceof ItemStore ? $store : throw new \RuntimeException('The project\'s items could not be loaded.');
  }

  /**
   * Runs the arena with the setup, which opens on its list of troops;
   * naming one skips straight to that fight.
   */
  protected function runArena(string $projectName, string $troop, BattleTestSetup $setup): void
  {
    new Game($projectName, options: [
      'starting_scene' => ArenaScene::class,
      'arena_troop' => $troop,
      ArenaScene::SETUP_OPTION => $setup,
    ])->run();
  }

  /**
   * The battle test setup the --member options describe, or the starting
   * party without them. Actors and items resolve by id or name; a member
   * without a level takes its actor's authored level.
   *
   * @param list<string> $members The --member values.
   * @throws InvalidArgumentException Naming every problem, before any battle starts.
   */
  /**
   * The setup fighting in one of the battle presentation's arenas, named by
   * key.
   *
   * @throws InvalidArgumentException When the project has no such arena, or no battle presentation at all.
   */
  protected function chooseArena(BattleTestSetup $setup, string $arena): BattleTestSetup
  {
    if (! method_exists($setup, 'withArena')) {
      throw new InvalidArgumentException('This project\'s engine cannot choose a battle test arena; update its engine to use --arena.');
    }

    $catalog = BattlePresentationCatalog::load('assets');

    if ($catalog === null) {
      throw new InvalidArgumentException('--arena: the project declares no battle presentation, so it has no arenas.');
    }

    $choices = $catalog->getArenaChoices();

    if (! array_key_exists($arena, $choices)) {
      throw new InvalidArgumentException(sprintf('--arena: the project has no arena %s (its arenas: %s).', $arena,
        implode(', ', array_map(static fn(string $key, string $name): string => sprintf('%s (%s)', $key, $name), array_keys($choices), $choices))));
    }

    return $setup->withArena($arena);
  }

  protected function createSetup(array $members): BattleTestSetup
  {
    $actors = $this->getActorStore();
    $items = $this->getItemStore();
    if ($members === []) {
      return BattleTestSetup::getFromStartingParty($actors);
    }
    if (count($members) > BattleTestSetup::MAX_MEMBERS) {
      throw new InvalidArgumentException(sprintf('A battle test party has at most %d members; %d were given.',
        BattleTestSetup::MAX_MEMBERS, count($members)));
    }

    $problems = [];
    $setupMembers = [];
    foreach ($members as $value) {
      try {
        $option = BattleMemberOption::parse(strval($value));
      } catch (InvalidArgumentException $invalid) {
        $problems[] = $invalid->getMessage();
        continue;
      }
      $actorId = $actors->canonicalId($option->actor);
      if ($actorId === null) {
        $problems[] = sprintf('--member %s: the project has no such actor (its actors: %s).', $option->actor, implode(', ', $actors->getActorIds()));
        continue;
      }
      $equipment = [];
      foreach ($option->equipment as $slot => $reference) {
        $item = $reference === null ? null : $items->get($reference);
        if ($reference !== null && $item === null) {
          $problems[] = sprintf('--member %s: the project has no item %s.', $option->actor, $reference);
          continue;
        }
        $equipment[$slot] = $item?->id;
      }
      $level = $option->level ?? $actors->require($actorId, 'ichiloto battle')->createCharacter()->level;
      if ($option->commands === null && $option->skills === [] && $option->summons === []) {
        $setupMembers[] = new BattleTestMember($actorId, $level, $equipment);
        continue;
      }
      if (! method_exists(BattleTestMember::class, 'withCommands')) {
        $problems[] = sprintf("--member %s: this project's engine cannot set a member's commands, skills or summons; update its engine.", $option->actor);
        continue;
      }
      $known = count($problems);
      $commands = $this->resolveCommands($option, $problems);
      $skills = $this->resolveSkills($option, $problems);
      $summons = $this->resolveSummons($option, $problems);
      // A member whose loadout does not resolve is not built; its problems are all reported.
      if (count($problems) === $known) {
        $setupMembers[] = new BattleTestMember($actorId, $level, $equipment, $commands, $skills, $summons);
      }
    }
    $setup = $setupMembers === [] ? null : new BattleTestSetup($setupMembers);
    $problems = [...$problems, ...($setup?->getProblems($actors, $items) ?? [])];
    if ($problems !== [] || $setup === null) {
      throw new InvalidArgumentException("The battle test party cannot be set up:\n" . implode("\n", $problems));
    }

    return $setup;
  }

  /**
   * Refuses a test loadout in a simulation, which would report numbers that
   * never used it: the simulator has every battler attack, so commands,
   * skills and summons change nothing there.
   *
   * @throws InvalidArgumentException When a member carries a loadout.
   */
  protected function refuseUnsimulatedLoadouts(BattleTestSetup $setup): void
  {
    foreach ($setup->members as $member) {
      if (($member->commands ?? null) !== null || ($member->skills ?? []) !== [] || ($member->summons ?? []) !== []) {
        throw new InvalidArgumentException(sprintf(
          '--member %s: --runs cannot use Commands, Skills or Summons. The simulator has every battler attack, so'
          . ' its numbers would never exercise them. Leave out --runs to play the fight and use them; levels and'
          . ' equipment still simulate.',
          $member->actorId,
        ));
      }
    }
  }

  /**
   * Resolves a member's Commands= list to the engine's command types, by id
   * or by the label the project shows for it.
   *
   * @param list<string> $problems Problems found, appended to.
   * @return list<BattleCommandType>|null The command menu, or null to keep the normal one.
   */
  protected function resolveCommands(BattleMemberOption $option, array &$problems): ?array
  {
    if ($option->commands === null) {
      return null;
    }
    $commands = [];
    foreach ($option->commands as $reference) {
      $command = BattleCommandType::fromCommandName($reference);
      if ($command === null) {
        $problems[] = sprintf('--member %s: there is no command %s (commands: %s).', $option->actor, $reference,
          implode(', ', array_map(static fn(BattleCommandType $type): string => $type->value, BattleCommandType::cases())));
        continue;
      }
      $commands[] = $command;
    }

    return $commands;
  }

  /**
   * Resolves a member's Skills= list against the project's skill catalogue,
   * which spans its abilities and spells wherever they are authored.
   *
   * @param list<string> $problems Problems found, appended to.
   * @return list<string> Canonical skill names.
   */
  protected function resolveSkills(BattleMemberOption $option, array &$problems): array
  {
    $catalog = SkillCatalog::getProjectCatalog();
    $skills = [];
    foreach ($option->skills as $reference) {
      if ($catalog->findSkill($reference) !== null) {
        $skills[] = $reference;
        continue;
      }
      $similar = array_values(array_filter(array_keys($catalog->getSkills()),
        static fn(string $name): bool => strcasecmp($name, $reference) === 0));
      $problems[] = sprintf('--member %s: the project has no skill %s%s.', $option->actor, $reference,
        $similar === [] ? '' : sprintf(' (did you mean %s?)', implode(' or ', $similar)));
    }

    return $skills;
  }

  /**
   * Resolves a member's Summons= list to the project's summon ids.
   *
   * @param list<string> $problems Problems found, appended to.
   * @return list<string> Summon ids.
   */
  protected function resolveSummons(BattleMemberOption $option, array &$problems): array
  {
    if ($option->summons === []) {
      return [];
    }
    $library = new SummonCutsceneLibrary();
    $summons = [];
    foreach ($option->summons as $reference) {
      try {
        $summon = $library->findById($reference);
      } catch (\Throwable $unreadable) {
        $problems[] = sprintf('--member %s: summon %s cannot be read: %s', $option->actor, $reference, $unreadable->getMessage());
        continue;
      }
      if ($summon === null) {
        $ids = array_map(static fn($definition): string => $definition->id, $library->load());
        $problems[] = sprintf('--member %s: the project has no summon %s (summons: %s).', $option->actor, $reference,
          $ids === [] ? 'none' : implode(', ', $ids));
        continue;
      }
      $summons[] = $summon->id;
    }

    return $summons;
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

    if (! ConfigStore::has(ActorStore::class)) {
      ConfigStore::put(ActorStore::class, new ActorStore());
    }

    if (! ConfigStore::has(EnemyStore::class)) {
      ConfigStore::put(EnemyStore::class, new EnemyStore());
    }
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
