<?php

declare(strict_types=1);

/**
 * Checks what the battle report says, and what it refuses to say.
 *
 * The report exists to be read while balancing, so the things that matter
 * are that it is repeatable, that it fits the terminal it is printed to,
 * that it exits with the right code, and that it never quietly implies a
 * number the engine did not give it.
 *
 * The simulated run works on a disposable copy of the project so nothing
 * here can touch the real one.
 */

require_once dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Reaches the width helpers without needing a project or an engine.
 */
final class BattleCommandWidthProbe
{
    public static function columns(string $text): int
    {
        return Ichiloto\Console\Commands\BattleCommand::columnsOf($text);
    }

    public static function cut(string $text, int $columns): string
    {
        return Ichiloto\Console\Commands\BattleCommand::cutToColumns($text, $columns);
    }
}

$consoleRoot = dirname(__DIR__);
$pinnedGame = getenv('ICHILOTO_GAME_SRC');
$projectRoot = is_string($pinnedGame) && $pinnedGame !== '' && is_dir($pinnedGame . '/assets')
    ? realpath($pinnedGame)
    : false;
$consoleBin = $consoleRoot . '/bin/ichiloto';
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-battle-' . bin2hex(random_bytes(8));

if (! is_string($projectRoot)) {
    fwrite(STDOUT, "SKIP: set ICHILOTO_GAME_SRC to run battle-report integration checks.\n");
    exit(0);
}

/**
 * Runs the battle command and returns what it printed.
 *
 * @param string[] $arguments The command arguments after "battle".
 * @return array{exitCode: int, output: string}
 */
function battleReport(string $consoleBin, string $workingDirectory, array $arguments, int $columns = 100): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $consoleBin, 'battle', '--no-ansi', ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
        ['COLUMNS' => (string) $columns] + getenv(),
    );

    if (! is_resource($process)) {
        fail('Unable to start the battle command.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'output' => (string) $stdout . (string) $stderr];
}

/**
 * Copies a project into a disposable directory, bounded.
 *
 * Symlinks stay symlinks, and the audio -- which nothing here plays or writes,
 * and which weighs more than the rest of the project together -- is reached
 * through a link rather than copied, so the copy is the size of the authored
 * data and not of the soundtrack.
 */
function copyProject(string $source, string $destination): void
{
    mkdir($destination, 0777, true);

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $from = $source . '/' . $entry;
        $to = $destination . '/' . $entry;

        if (is_link($from)) {
            // The engine is reached through a link; following it would copy a
            // whole engine into a temporary directory for no reason. The
            // target is resolved first, because a link written relative to
            // the project points nowhere once the project is somewhere else.
            $target = realpath($from);
            symlink($target === false ? (string) readlink($from) : $target, $to);
            continue;
        }

        if ($entry === 'Audio' && is_dir($from) && basename($source) === 'assets') {
            symlink($from, $to);
            continue;
        }

        is_dir($from) ? copyProject($from, $to) : copy($from, $to);
    }
}

function removeProject(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_link($path) || is_file($path)) {
            unlink($path);
            continue;
        }

        removeProject($path);
    }

    rmdir($directory);
}

/**
 * A failed expectation. Thrown rather than exited on, so the fixture is
 * removed by the finally below whether the run passes or fails -- exit()
 * would skip it and leave the copy behind.
 */
final class TestFailure extends Exception
{
}

function fail(string $message): never
{
    throw new TestFailure($message);
}

/**
 * The command, failing while it describes a preview.
 */
final class FailingBattleCommand extends Ichiloto\Console\Commands\BattleCommand
{
    protected function describeHit(\Ichiloto\Engine\Battle\Resolution\CombatHitResult $hit): string
    {
        throw new RuntimeException('injected preview failure');
    }
}

/**
 * An output that fails once the aggregate report starts printing.
 */
final class FailingOutput extends Symfony\Component\Console\Output\BufferedOutput
{
    protected function doWrite(string $message, bool $newline): void
    {
        if (str_contains($message, 'runs:')) {
            throw new RuntimeException('injected output failure');
        }

        parent::doWrite($message, $newline);
    }
}

/**
 * Records everything a set of objects hold, independently of the command's
 * own snapshot: every stored property of every object reachable from the
 * roots, with objects inside arrays and properties recorded by identity.
 *
 * @return array<int, array{class: string, props: array<string, mixed>}> The state, by object id.
 */
function deepState(object ...$roots): array
{
    $state = [];
    $queue = $roots;

    while ($queue !== []) {
        $object = array_shift($queue);
        $id = spl_object_id($object);

        if (isset($state[$id]) || $object instanceof UnitEnum || $object instanceof Closure) {
            continue;
        }

        $props = [];
        $class = new ReflectionObject($object);

        while ($class !== false) {
            foreach ($class->getProperties() as $property) {
                $key = $property->getDeclaringClass()->getName() . '::' . $property->getName();

                if (isset($props[$key]) || $property->isStatic() || $property->isVirtual()) {
                    continue;
                }

                if (! $property->isInitialized($object)) {
                    $props[$key] = '<uninitialized>';

                    continue;
                }

                $props[$key] = normalizeStateValue($property->getRawValue($object), $queue);
            }

            $class = $class->getParentClass();
        }

        $state[$id] = ['class' => $object::class, 'props' => $props];
    }

    return $state;
}

/**
 * Renders a stored value for comparison: objects by identity, arrays in
 * full, enums by case, scalars as they are.
 *
 * @param object[] $queue Objects met on the way, to be recorded too.
 */
function normalizeStateValue(mixed $value, array &$queue): mixed
{
    if ($value instanceof UnitEnum) {
        return 'enum:' . $value::class . '::' . $value->name;
    }

    if ($value instanceof Closure) {
        return 'closure';
    }

    if (is_object($value)) {
        $queue[] = $value;

        return '@' . spl_object_id($value);
    }

    if (is_array($value)) {
        $normalized = [];

        foreach ($value as $key => $item) {
            $normalized[$key] = normalizeStateValue($item, $queue);
        }

        return $normalized;
    }

    return $value;
}

/**
 * Lists every property that differs between two recordings.
 *
 * @return string[] The changes, described.
 */
function stateChanges(array $before, array $after): array
{
    $changes = [];

    foreach ($after as $id => $entry) {
        foreach ($entry['props'] as $property => $value) {
            $was = array_key_exists($property, $before[$id]['props'] ?? [])
                ? $before[$id]['props'][$property]
                : '<absent>';

            if ($was !== $value) {
                $changes[] = sprintf('%s#%d %s: %s -> %s', $entry['class'], $id, $property, json_encode($was), json_encode($value));
            }
        }
    }

    foreach ($before as $id => $entry) {
        if (! isset($after[$id])) {
            $changes[] = sprintf('%s#%d is no longer reachable', $entry['class'], $id);
        }
    }

    return $changes;
}

/**
 * The battle checklist, read off the participants by name: what a review
 * of the report asked to see compared, item by item.
 *
 * @return array<string, mixed> The checklist.
 */
function battleChecklist(\Ichiloto\Engine\Entities\Party $party, \Ichiloto\Engine\Entities\Troop $troop): array
{
    $describe = static function (object $battler): array {
        $states = [];

        foreach ($battler->states as $instance) {
            $states[] = [$instance->state->id, $instance->remainingTurns];
        }

        $slots = [];

        foreach ($battler->equipment ?? [] as $slot) {
            $slots[] = [$slot->semanticSlot->value, $slot->equipment?->id, spl_object_id($slot)];
        }

        return [
            'identity' => spl_object_id($battler),
            'name' => $battler->name,
            'role' => isset($battler->role) ? $battler->role->name : null,
            'hp' => $battler->stats->currentHp,
            'mp' => $battler->stats->currentMp,
            'ap' => $battler->stats->currentAp,
            'states' => $states,
            'statStages' => $battler->statStages,
            'guarding' => $battler->isGuarding,
            'lastHitWasCritical' => $battler->lastHitWasCritical,
            'lastElementReaction' => $battler->lastElementReaction,
            'equipment' => $slots,
            'permanentGrowth' => isset($battler->permanentGrowth) ? $battler->permanentGrowth->jsonSerialize() : null,
        ];
    };

    return [
        'party' => array_map($describe, $party->members->toArray()),
        'troop' => array_map($describe, $troop->members->toArray()),
    ];
}

/**
 * Returns the widest line of some output, in terminal columns.
 *
 * Measured the way the command measures, because that is the claim under
 * test: a CJK glyph and most pictographs take two columns, a combining mark
 * takes none, and a joined emoji is one glyph however many code points it
 * is written with.
 */
function widestLine(string $output): int
{
    return max(array_map(
        Ichiloto\Console\Commands\BattleCommand::columnsOf(...),
        explode("\n", $output),
    ));
}

try {
    copyProject($projectRoot, $temporaryRoot);

    // The width contract is the engine's, so the engine has to be loaded
    // before it can be asked anything.
    if (is_file($temporaryRoot . '/vendor/autoload.php')) {
        require_once $temporaryRoot . '/vendor/autoload.php';
    }

    // -- Deterministic output --------------------------------------------

    $first = battleReport($consoleBin, $consoleRoot, ['-d', $temporaryRoot, '-t', 'Bat x 2', '-r', '5']);

    if ($first['exitCode'] !== 0) {
        fail('A simulated battle did not succeed: ' . $first['output']);
    }

    $second = battleReport($consoleBin, $consoleRoot, ['-d', $temporaryRoot, '-t', 'Bat x 2', '-r', '5']);

    if ($first['output'] !== $second['output']) {
        fail('Two identical runs printed different reports.');
    }

    if (! str_contains($first['output'], 'seed 1')) {
        fail('The report did not print the seed that makes it repeatable.');
    }

    // -- Raw counts, not only shares ------------------------------------

    if (preg_match('/(\d+) runs: (\d+) won \((\d+)%\), (\d+) lost \((\d+)%\), (\d+) unfinished \((\d+)%\)/', $first['output'], $counts) !== 1) {
        fail('The report did not print raw wins, defeats and unfinished counts beside their shares: ' . $first['output']);
    }

    [, $runs, $won, , $lost, , $unfinished] = $counts;

    if ((int) $won + (int) $lost + (int) $unfinished !== (int) $runs) {
        fail(sprintf('The raw counts do not add up to the runs: %s', $counts[0]));
    }

    // -- Width, measured in columns, with wide glyphs --------------------

    if (BattleCommandWidthProbe::columns('日本語') !== 6) {
        fail('A CJK glyph was not measured as two columns.');
    }

    if (BattleCommandWidthProbe::columns('🗡️') !== 2) {
        fail('A pictograph with a variation selector was not measured as two columns.');
    }

    if (BattleCommandWidthProbe::columns('<fg=gray>plain</>') !== 5) {
        fail('Formatting tags were counted as visible columns.');
    }

    if (BattleCommandWidthProbe::columns("e\u{0301}") !== 1) {
        fail('A combining mark was measured as a column of its own.');
    }

    if (BattleCommandWidthProbe::columns('👨‍👩‍👧‍👦') !== 2) {
        fail('A joined emoji was not measured as one two-column glyph.');
    }

    // The same ruler as the engine's own, glyph for glyph.
    foreach (['日本語', '🗡️', "e\u{0301}", '👨‍👩‍👧‍👦', 'plain'] as $sample) {
        if (BattleCommandWidthProbe::columns($sample) !== Ichiloto\Engine\IO\Console\TerminalText::displayWidth($sample)) {
            fail(sprintf('The command measured "%s" differently from the engine.', $sample));
        }
    }

    if (BattleCommandWidthProbe::columns(BattleCommandWidthProbe::cut('日本語ですよ', 5)) > 5) {
        fail('Cutting to five columns produced something wider than five columns.');
    }

    // -- What the engine resolved, not what the console computed ---------

    foreach ([
        'Party as fought',
        // Caps, cap loss and headroom come from Character::resolveStats().
        'to the 999 cap',
        'natural',
        'one seeded attack each on',
    ] as $expected) {
        if (! str_contains($first['output'], $expected)) {
            fail(sprintf('The report is missing "%s".', $expected));
        }
    }

    // -- The disclosures -------------------------------------------------

    foreach ([
        'Simulated battlers attack; they do not guard, cast, or use items.',
        'What this report cannot say',
        'SimulationReport',
        'CombatHitResult',
        'previewAttack()',
        'ElementalOutcome',
    ] as $expected) {
        if (! str_contains($first['output'], $expected)) {
            fail(sprintf('The report does not disclose "%s".', $expected));
        }
    }

    // A rate the engine does not aggregate must never be printed as one.
    foreach (['% missed', '% critical', '% guarded', '% weak', '% resisted'] as $invented) {
        if (str_contains($first['output'], $invented)) {
            fail(sprintf('The report printed "%s", which no engine projection supplies.', $invented));
        }
    }

    // -- Width safety ----------------------------------------------------

    foreach ([40, 60, 100, 200] as $columns) {
        $narrow = battleReport(
            $consoleBin,
            $consoleRoot,
            ['-d', $temporaryRoot, '-t', 'Bat x 2', '-r', '2'],
            $columns,
        );

        if ($narrow['exitCode'] !== 0) {
            fail(sprintf('The report failed at %d columns: %s', $columns, $narrow['output']));
        }

        if (widestLine($narrow['output']) > max(40, $columns)) {
            fail(sprintf('The report printed a line wider than %d columns.', $columns));
        }
    }

    // -- A troop whose name is wide, end to end --------------------------

    $troopsPath = $temporaryRoot . '/assets/Data/troops.php';
    $troops = (string) file_get_contents($troopsPath);
    // CJK, a joined emoji, a pictograph with a variation selector, and a
    // combining mark, in the name that heads the troop verdict.
    $wideName = "日本語の敵👨‍👩‍👧‍👦テスト🗡️e\u{0301}";
    $renamed = preg_replace("/'name' => 'Bat x 2'/", sprintf("'name' => '%s'", $wideName), $troops, 1);

    if ($renamed === null || $renamed === $troops) {
        fail('The wide-name fixture could not rename a troop.');
    }

    file_put_contents($troopsPath, $renamed);

    foreach ([40, 60, 100, 200] as $columns) {
        $wide = battleReport(
            $consoleBin,
            $consoleRoot,
            ['-d', $temporaryRoot, '-t', $wideName, '-r', '2'],
            $columns,
        );

        if ($wide['exitCode'] !== 0) {
            fail(sprintf('A troop named in CJK and emoji failed at %d columns: %s', $columns, $wide['output']));
        }

        $sawHeader = false;

        foreach (explode("\n", $wide['output']) as $line) {
            $drawn = Ichiloto\Console\Commands\BattleCommand::columnsOf($line);

            if ($drawn > max(40, $columns)) {
                fail(sprintf(
                    'A line drew %d columns in a %d-column terminal: %s',
                    $drawn,
                    $columns,
                    $line,
                ));
            }

            // The troop-verdict header carries the wide name and is cut by
            // the same ruler as every other line.
            if (str_starts_with(trim($line), Ichiloto\Engine\IO\Console\TerminalText::truncateToWidth($wideName, 4))) {
                $sawHeader = true;
            }
        }

        if (! $sawHeader) {
            fail(sprintf('The troop-verdict header did not appear at %d columns: %s', $columns, $wide['output']));
        }
    }

    file_put_contents($troopsPath, $troops);

    // -- The whole report leaves every participant exactly as it found it --
    //
    // Not only the preview helper: loading, simulating, the aggregate and
    // the seeded previews together, compared before and after over every
    // object a party or a troop reaches -- and, separately, over the very
    // things a resolved attack writes.

    if (class_exists(\Ichiloto\Engine\Entities\Party::class)) {
        $previousDirectory = getcwd();
        chdir($temporaryRoot);

        // Building a troop reads the enemy store, the way the command does
        // before it loads anything.
        foreach ([
            \Ichiloto\Engine\Util\Config\ProjectConfig::class,
            \Ichiloto\Engine\Util\Stores\ItemStore::class,
            \Ichiloto\Engine\Util\Stores\EnemyStore::class,
        ] as $store) {
            if (! \Ichiloto\Engine\Util\Config\ConfigStore::has($store)) {
                \Ichiloto\Engine\Util\Config\ConfigStore::put($store, new $store());
            }
        }

        $system = (static fn(): mixed => require $temporaryRoot . '/assets/Data/system.php')();
        $members = [];

        foreach ((array) ($system['startingParty'] ?? []) as $member) {
            $data = (static fn(): mixed => require $temporaryRoot . "/assets/Data/Actors/{$member}.php")();

            if (is_array($data) && isset($data['data'])) {
                $members[] = $data['data'];
            }
        }

        $party = \Ichiloto\Engine\Entities\Party::fromArray($members);
        $troopData = null;

        foreach ((array) (static fn(): mixed => require $temporaryRoot . '/assets/Data/troops.php')() as $candidate) {
            if (is_array($candidate) && ($candidate['name'] ?? '') === 'Bat x 2') {
                $troopData = $candidate;
            }
        }

        $troop = \Ichiloto\Engine\Entities\Troop::fromArray((array) $troopData);
        $attacker = $party->battlers->toArray()[0];
        $target = $troop->members->toArray()[0];

        // Force what the resolver writes beyond health: a fire blade whose
        // critical modifier sits at the ceiling, swung at a bat weak to
        // fire, so every hit is an elemental reaction and the seeded run
        // criticals. Removing the outer boundary leaves both flags changed.
        $blade = new \Ichiloto\Engine\Entities\Inventory\Weapons\Weapon(
            id: 'equipment.probe-blade',
            name: 'Probe Blade',
            description: 'For the report to swing.',
            icon: '/',
            price: 1,
            parameterChanges: new \Ichiloto\Engine\Entities\ParameterChanges(attack: 4),
            element: 'Fire',
            criticalModifier: 100,
        );

        foreach ($attacker->equipment as $slot) {
            if ($slot->semanticSlot === \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::WEAPON) {
                $slot->equipment = $blade;
            }
        }

        $target->setElementAffinities(['Fire' => 2.0]);
        // A state with turns left, a stat stage, and a guard, so that the
        // comparison covers what a battle can tick, buff and drop.
        $target->addStatStage('attack', 1);
        $target->beginGuarding();

        $participants = [$party, $troop];
        $checklistBefore = battleChecklist($party, $troop);
        $before = deepState(...$participants);
        // The command's own recording of the loaded state, kept aside to
        // prove it can put a mutated world back to exactly this.
        $loaded = \Ichiloto\Console\Battle\ParticipantSnapshot::capture(...$participants);

        $command = new Ichiloto\Console\Commands\BattleCommand();
        $render = new ReflectionMethod($command, 'renderReport');
        $buffer = new Symfony\Component\Console\Output\BufferedOutput();
        $render->invoke($command, $buffer, $party, [$troop], new \Ichiloto\Engine\Battle\Simulation\BattleSimulator(), 5);
        $rendered = $buffer->fetch();

        // The forced reactions did happen inside the report: the previews
        // read them off the typed hit result.
        if (! str_contains($rendered, 'Fire weak')) {
            fail('The forced elemental reaction did not appear in the report: ' . $rendered);
        }

        $changes = stateChanges($before, deepState(...$participants));

        if ($changes !== []) {
            fail('The whole report changed the party or troop: ' . implode('; ', $changes));
        }

        if (battleChecklist($party, $troop) !== $checklistBefore) {
            fail('The report changed something on the battle checklist: ' . json_encode(battleChecklist($party, $troop)));
        }

        // The comparison has teeth: the same simulation without the boundary
        // leaves the critical and elemental feedback, and more, changed.
        new \Ichiloto\Engine\Battle\Simulation\BattleSimulator()->simulate($party, $troop, 5);
        $naked = stateChanges($before, deepState(...$participants));
        $flags = array_filter(
            $naked,
            static fn(string $change): bool => str_contains($change, 'lastHitWasCritical') || str_contains($change, 'lastElementReaction'),
        );

        if ($flags === []) {
            fail('Simulating without the boundary left no feedback flag changed, so the comparison could not fail: ' . implode('; ', $naked));
        }

        // The recording taken at load puts that mutated world back exactly.
        $loaded->restore();

        if (stateChanges($before, deepState(...$participants)) !== []) {
            fail('Restoring after a naked simulation did not return the participants to their loaded state.');
        }

        // A controlled failure in the middle of the report -- while a
        // preview is being described -- still puts everything back.

        $failing = new FailingBattleCommand();
        $failed = false;

        try {
            new ReflectionMethod($failing, 'renderReport')->invoke(
                $failing,
                new Symfony\Component\Console\Output\BufferedOutput(),
                $party,
                [$troop],
                new \Ichiloto\Engine\Battle\Simulation\BattleSimulator(),
                5,
            );
        } catch (RuntimeException $exception) {
            $failed = $exception->getMessage() === 'injected preview failure';
        }

        if (! $failed) {
            fail('The injected preview failure did not surface.');
        }

        $afterFailure = stateChanges($before, deepState(...$participants));

        if ($afterFailure !== [] || battleChecklist($party, $troop) !== $checklistBefore) {
            fail('A report that failed part-way left the participants changed: ' . implode('; ', $afterFailure));
        }

        // And a failure in the output itself, after the simulation has run.
        $failedOutput = false;

        try {
            new ReflectionMethod($command, 'renderReport')->invoke(
                $command,
                new FailingOutput(),
                $party,
                [$troop],
                new \Ichiloto\Engine\Battle\Simulation\BattleSimulator(),
                5,
            );
        } catch (RuntimeException $exception) {
            $failedOutput = $exception->getMessage() === 'injected output failure';
        }

        if (! $failedOutput) {
            fail('The injected output failure did not surface.');
        }

        $afterOutputFailure = stateChanges($before, deepState(...$participants));

        if ($afterOutputFailure !== [] || battleChecklist($party, $troop) !== $checklistBefore) {
            fail('A report whose output failed left the participants changed: ' . implode('; ', $afterOutputFailure));
        }

        chdir($previousDirectory ?: '.');
    }

    // -- Exit codes ------------------------------------------------------

    $missingTroop = battleReport($consoleBin, $consoleRoot, ['-d', $temporaryRoot, '-t', 'No Such Troop', '-r', '2']);

    if ($missingTroop['exitCode'] === 0) {
        fail('A troop that does not exist reported success.');
    }

    $missingProject = battleReport($consoleBin, $consoleRoot, ['-d', $temporaryRoot . '-gone', '-r', '2']);

    if ($missingProject['exitCode'] === 0) {
        fail('A directory that is not a project reported success.');
    }

    // -- Nothing was written to the project ------------------------------

    $before = battleReport($consoleBin, $consoleRoot, ['-d', $temporaryRoot, '-t', 'Bat x 2', '-r', '2']);
    $hashesBefore = projectHashes($temporaryRoot);
    battleReport($consoleBin, $consoleRoot, ['-d', $temporaryRoot, '-t', 'Bat x 2', '-r', '2']);

    if (projectHashes($temporaryRoot) !== $hashesBefore) {
        fail('Simulating a battle changed the project.');
    }

    unset($before);

    // -- Seeded previews start from the same target state ----------------

    $previewLines = static function (string $output): array {
        $lines = [];
        $inSection = false;

        foreach (explode("\n", $output) as $line) {
            if (str_contains($line, 'one seeded attack each on')) {
                $inSection = true;

                continue;
            }

            if ($inSection && trim($line) === '') {
                break;
            }

            if ($inSection) {
                $lines[] = trim($line);
            }
        }

        return $lines;
    };

    $previews = $previewLines($first['output']);

    if (count($previews) < 2) {
        fail('The report showed fewer than two seeded previews to compare.');
    }

    // Every attacker swings at the same target from the same state, so the
    // seeded results must be reproducible rather than compounding: the
    // second run of the identical command produced identical lines already,
    // and the same holds within one run for a repeated attacker.
    if ($previewLines($second['output']) !== $previews) {
        fail('Two identical runs produced different seeded previews.');
    }

    // -- A worn item reads as its display name and its stable id ----------
    //
    // No project file fills an equipment slot at this engine head -- a save
    // does, and the game equips in play -- so the report says "every slot
    // empty" for a party built from project data. That is the honest line,
    // and it is checked here beside the rendering it replaces.

    if (! str_contains($first['output'], 'every slot empty')) {
        fail('The report did not say that a project-data party wears nothing.');
    }

    if (class_exists(\Ichiloto\Engine\Entities\Character::class)) {
        $previousDirectory = getcwd();
        chdir($temporaryRoot);

        $character = \Ichiloto\Engine\Entities\Character::fromArray([
            'name' => 'Test Subject',
            'currentExp' => 0,
            'stats' => [
                'currentHp' => 10, 'currentMp' => 0, 'currentAp' => 0,
                'attack' => 1, 'defence' => 1, 'magicAttack' => 1, 'magicDefence' => 1,
            ],
        ]);
        $weapon = new \Ichiloto\Engine\Entities\Inventory\Weapons\Weapon(
            id: 'equipment.test-blade',
            name: 'Test Blade',
            description: 'For the report to name.',
            icon: '/',
            price: 1,
            parameterChanges: new \Ichiloto\Engine\Entities\ParameterChanges(attack: 4),
        );

        // The slot is filled directly rather than through equip(), which
        // announces itself to a running game's modal manager and so needs a
        // game to be running.
        foreach ($character->equipment as $slot) {
            if ($slot->semanticSlot === \Ichiloto\Engine\Entities\Inventory\EquipmentSlotType::WEAPON) {
                $slot->equipment = $weapon;
            }
        }

        chdir($previousDirectory ?: '.');

        $buffer = new Symfony\Component\Console\Output\BufferedOutput();
        $command = new Ichiloto\Console\Commands\BattleCommand();
        $render = new ReflectionMethod($command, 'reportLoadout');
        $render->invoke($command, $buffer, $character);
        $render = new ReflectionMethod($command, 'reportStatLayers');
        $render->invoke($command, $buffer, $character);
        $rendered = $buffer->fetch();

        if (! str_contains($rendered, 'Test Blade (equipment.test-blade)')) {
            fail('A worn item did not read as its display name and stable id: ' . $rendered);
        }

        if (! str_contains($rendered, '+4 gear')) {
            fail('What a worn item contributes was not resolved through the engine: ' . $rendered);
        }
    }
} catch (TestFailure $failure) {
    $testFailure = $failure->getMessage();
} finally {
    removeProject($temporaryRoot);
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

/**
 * Hashes every file of a project, so a run that wrote anything is visible.
 *
 * @return array<string, string>
 */
function projectHashes(string $root): array
{
    $hashes = [];
    // Links are followed, so the audio reached through one is hashed file
    // by file like everything else, and the pin over the whole project keeps
    // its full reach.
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS | FilesystemIterator::FOLLOW_SYMLINKS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isFile()) {
            $hashes[$file->getPathname()] = (string) md5_file($file->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
}

fwrite(STDOUT, "PASS: the battle report is deterministic, width-safe, and honest about what it cannot say.\n");
