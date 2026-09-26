<?php

declare(strict_types=1);

use Ichiloto\Engine\Field\MapLayerSource;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;
use Ichiloto\Engine\IO\SaveCompatibility\TwoColumnCellsMigration;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * Runs `ichiloto upgrade` on a synthetic format-1 project and compares every
 * resulting file, the report included, with the committed expected tree:
 * odd and ragged rows, markup-styled pairs, a misaligned emoji, conflicting
 * event markers, NPCs and wander areas, spawn points and scripts, a common
 * event, a cinematic, system start positions, tile crops, the save manifest,
 * and the reachability review: a one-column doorway that closes cuts off a
 * room with an event, an NPC and a trigger, and splits two arrivals. Also checks the dry run, the non-interactive
 * confirmation, the uncommitted-changes refusal and idempotency.
 */

$consoleRoot = dirname(__DIR__);
$consoleBin = $consoleRoot . '/bin/ichiloto';
$fixtureRoot = __DIR__ . '/fixtures/two-column-cells';
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-two-column-upgrade-' . bin2hex(random_bytes(8));

final class TwoColumnUpgradeFailure extends Exception
{
}

function failTwoColumnUpgrade(string $message): never
{
    throw new TwoColumnUpgradeFailure($message);
}

/** @return array{exitCode: int, output: string} */
function runTwoColumnUpgrade(string $consoleBin, string $projectRoot, array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $consoleBin, 'upgrade', '--no-ansi', ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
        $projectRoot,
    );

    if (! is_resource($process)) {
        failTwoColumnUpgrade('Unable to start the upgrade command.');
    }

    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'output' => trim($output)];
}

function runFixtureGit(string $directory, string ...$arguments): void
{
    $command = array_merge(['git', '-C', $directory, '-c', 'user.name=Fixture', '-c', 'user.email=fixture@example.invalid'], $arguments);
    $process = proc_open($command, [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);

    if (! is_resource($process)) {
        failTwoColumnUpgrade('Git is required for this test.');
    }

    $output = stream_get_contents($pipes[1]) . stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    if (proc_close($process) !== 0) {
        failTwoColumnUpgrade('git ' . implode(' ', $arguments) . ' failed: ' . $output);
    }
}

function copyTwoColumnTree(string $source, string $destination): void
{
    if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
        failTwoColumnUpgrade("Unable to create {$destination}.");
    }

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $from = $source . DIRECTORY_SEPARATOR . $entry;
        $to = $destination . DIRECTORY_SEPARATOR . $entry;
        is_dir($from) ? copyTwoColumnTree($from, $to) : copy($from, $to);
    }
}

function removeTwoColumnTree(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) && ! is_link($path) ? removeTwoColumnTree($path) : unlink($path);
    }

    rmdir($directory);
}

/** @return array<string, string> File contents by relative path, without Git's own files. */
function readTwoColumnTree(string $root, string $prefix = ''): array
{
    $files = [];

    foreach (scandir($root . $prefix) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..' || ($prefix === '' && $entry === '.git')) {
            continue;
        }

        $relative = $prefix . '/' . $entry;
        $path = $root . $relative;
        $files += is_dir($path) ? readTwoColumnTree($root, $relative) : [ltrim($relative, '/') => (string) file_get_contents($path)];
    }

    ksort($files);

    return $files;
}

/** @param array<string, string> $expected @param array<string, string> $actual */
function assertSameTree(array $expected, array $actual, string $context): void
{
    if (array_keys($expected) !== array_keys($actual)) {
        failTwoColumnUpgrade(sprintf(
            '%s: the files differ. Missing: %s. Unexpected: %s.',
            $context,
            implode(', ', array_diff(array_keys($expected), array_keys($actual))) ?: 'none',
            implode(', ', array_diff(array_keys($actual), array_keys($expected))) ?: 'none',
        ));
    }

    foreach ($expected as $path => $contents) {
        if ($actual[$path] !== $contents) {
            failTwoColumnUpgrade("{$context}: {$path} differs from the expected output.");
        }
    }
}

function createCommittedProject(string $fixtureRoot, string $projectRoot): void
{
    copyTwoColumnTree($fixtureRoot . '/project', $projectRoot);
    runFixtureGit($projectRoot, 'init', '-q');
    runFixtureGit($projectRoot, 'add', '-A');
    runFixtureGit($projectRoot, 'commit', '-q', '-m', 'Format 1 fixture');
}

try {
    $project = $temporaryRoot . '/project';
    createCommittedProject($fixtureRoot, $project);
    $original = readTwoColumnTree($project);
    $expected = readTwoColumnTree($fixtureRoot . '/expected');

    $dryRun = runTwoColumnUpgrade($consoleBin, $project, ['--dry-run']);
    foreach ([
        'This project is at format 1; Ichiloto reads format 2. The upgrade will:',
        'Regroup 3 maps into two-column cells: 5 of 8 grid files change (29 rows padded to an even width, 1 two-column glyph moved right).',
        'Halve 22 field x coordinates in 5 files.',
        'Halve 3 horizontal move route steps (each is listed for review).',
        'Remove retired tiles2d crops from 1 map.',
        'Add save migration 2 to 3 (TwoColumnCellsMigration)',
        'Report 2 items that block loading and 18 for review or hand conversion.',
        'Dry run only; no files were changed.',
    ] as $line) {
        if ($dryRun['exitCode'] !== 0 || ! str_contains($dryRun['output'], $line)) {
            failTwoColumnUpgrade("The dry run did not list \"{$line}\": {$dryRun['output']}");
        }
    }
    assertSameTree($original, readTwoColumnTree($project), 'The dry run');

    $unconfirmed = runTwoColumnUpgrade($consoleBin, $project, []);
    if ($unconfirmed['exitCode'] === 0 || ! str_contains($unconfirmed['output'], 'Pass --yes')) {
        failTwoColumnUpgrade('A non-interactive upgrade ran without --yes: ' . $unconfirmed['output']);
    }
    assertSameTree($original, readTwoColumnTree($project), 'The unconfirmed upgrade');

    file_put_contents($project . '/notes.txt', "Unfinished work.\n");
    $dirty = runTwoColumnUpgrade($consoleBin, $project, ['--yes']);
    if ($dirty['exitCode'] === 0 || ! str_contains($dirty['output'], '1 uncommitted change')) {
        failTwoColumnUpgrade('The upgrade ran over uncommitted changes: ' . $dirty['output']);
    }
    unlink($project . '/notes.txt');
    assertSameTree($original, readTwoColumnTree($project), 'The refused upgrade');

    $upgrade = runTwoColumnUpgrade($consoleBin, $project, ['--yes']);
    if ($upgrade['exitCode'] !== 0) {
        failTwoColumnUpgrade('The upgrade failed: ' . $upgrade['output']);
    }
    assertSameTree($expected, readTwoColumnTree($project), 'The upgrade');

    foreach ([
        '✓ Upgraded the project to format 2.',
        'Maps that will not load until fixed:',
        'assets/Maps/village/village.event.php row 2, cell 1 holds two different markers, B and C.',
        'grove: Event map assets/Maps/grove/grove.event.php row 1 must be 4 cells wide.',
        'village: 6 cells became solid; reachability was compared from 3 entry points.',
        'village: event E can no longer be reached from the map\'s entry points.',
        'village: NPC at assets/Maps/village/village.data.php:28 npcs[1] can no longer be reached from the map\'s entry points.',
        'village: trigger at assets/Maps/village/village.data.php:46 triggers[0].trigger_area can no longer be reached',
        'village: 7 walkable cells around cell (1, 4) can no longer be reached; likely closed by cell (2, 3), ".#", which became solid.',
        'cave: the arrival at assets/Maps/village/village.data.php:56 events.A.data.spawnPoint no longer connects to 1 other entry point',
        'Follow-up list written to ' . realpath($project) . '/ichiloto-upgrade-report.md',
    ] as $line) {
        if (! str_contains($upgrade['output'], $line)) {
            failTwoColumnUpgrade("The upgrade did not print \"{$line}\": {$upgrade['output']}");
        }
    }

    // The engine reads what the upgrade wrote.
    $village = MapLayerSource::loadFromDirectory($project . '/assets/Maps/village', 'village');
    if (count($village->getComposedGrid()[0]) !== 7 || count($village->getComposedGrid()[5]) !== 4) {
        failTwoColumnUpgrade('The engine did not read the converted village as rows of two-column cells.');
    }
    $manifest = SaveCompatibilityManifest::fromArray('fixture/two-column-cells', require $project . '/assets/Data/save-compatibility.php');
    if ($manifest->contentVersion !== 3 || $manifest->migrationFrom(2) !== TwoColumnCellsMigration::class) {
        failTwoColumnUpgrade('The engine did not accept the appended save migration.');
    }

    $again = runTwoColumnUpgrade($consoleBin, $project, ['--yes']);
    if ($again['exitCode'] !== 0 || ! str_contains($again['output'], 'No upgrade is needed; the project is already at format 2.')) {
        failTwoColumnUpgrade('An up-to-date project was not reported as such: ' . $again['output']);
    }
    assertSameTree($expected, readTwoColumnTree($project), 'The repeated upgrade');

    $allowed = $temporaryRoot . '/allowed';
    createCommittedProject($fixtureRoot, $allowed);
    file_put_contents($allowed . '/notes.txt', "Unfinished work.\n");
    $allowedRun = runTwoColumnUpgrade($consoleBin, $allowed, ['--yes', '--allow-dirty']);
    if ($allowedRun['exitCode'] !== 0) {
        failTwoColumnUpgrade('--allow-dirty did not permit the upgrade: ' . $allowedRun['output']);
    }
    assertSameTree($expected + ['notes.txt' => "Unfinished work.\n"], readTwoColumnTree($allowed), 'The allowed dirty upgrade');

    $newer = $temporaryRoot . '/newer';
    copyTwoColumnTree($fixtureRoot . '/project', $newer);
    file_put_contents($newer . '/ichiloto.json', "{\n  \"id\": \"fixture/newer\",\n  \"format\": 99\n}\n");
    $newerRun = runTwoColumnUpgrade($consoleBin, $newer, ['--yes']);
    if ($newerRun['exitCode'] === 0 || ! str_contains($newerRun['output'], 'newer than this Ichiloto reads')) {
        failTwoColumnUpgrade('A project from a newer format was not refused: ' . $newerRun['output']);
    }
} catch (TwoColumnUpgradeFailure $failure) {
    $testFailure = $failure->getMessage();
} finally {
    removeTwoColumnTree($temporaryRoot);
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: upgrade converts a project to two-column cells exactly, reports what needs a person, and protects uncommitted work.\n");
