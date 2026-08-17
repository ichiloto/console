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

$consoleRoot = dirname(__DIR__);
$workspaceRoot = dirname($consoleRoot);
$projectRoot = realpath($workspaceRoot . '/examples/last-legend');
$consoleBin = $consoleRoot . '/bin/ichiloto';
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-battle-' . bin2hex(random_bytes(8));

if (! is_string($projectRoot)) {
    fail('The sibling Last Legend worktree was not found.');
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
 * Copies a directory, keeping symlinks as symlinks.
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

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

/**
 * Returns the longest line of some output, measured in characters rather
 * than bytes: a report full of multibyte separators is not too wide because
 * of them.
 */
function longestLine(string $output): int
{
    return max(array_map(mb_strlen(...), explode("\n", $output)));
}

try {
    copyProject($projectRoot, $temporaryRoot);

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

        if (longestLine($narrow['output']) > max(40, $columns)) {
            fail(sprintf('The report printed a line wider than %d columns.', $columns));
        }
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

    // -- A worn item reads as its display name and its stable id ----------
    //
    // No project file fills an equipment slot at this engine head -- a save
    // does, and the game equips in play -- so the report says "every slot
    // empty" for a party built from project data. That is the honest line,
    // and it is checked here beside the rendering it replaces.

    if (! str_contains($first['output'], 'every slot empty')) {
        fail('The report did not say that a project-data party wears nothing.');
    }

    if (is_file($temporaryRoot . '/vendor/autoload.php')) {
        require_once $temporaryRoot . '/vendor/autoload.php';
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
} finally {
    removeProject($temporaryRoot);
}

/**
 * Hashes every file of a project, so a run that wrote anything is visible.
 *
 * @return array<string, string>
 */
function projectHashes(string $root): array
{
    $hashes = [];
    $files = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::SELF_FIRST,
    );

    foreach ($files as $file) {
        if ($file->isFile() && ! $file->isLink()) {
            $hashes[$file->getPathname()] = (string) md5_file($file->getPathname());
        }
    }

    ksort($hashes);

    return $hashes;
}

fwrite(STDOUT, "PASS: the battle report is deterministic, width-safe, and honest about what it cannot say.\n");
