<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\MapApplyCommand;
use Ichiloto\Console\Commands\ValidateCommand;
use Ichiloto\Console\Support\NewProjectScaffolder;
use Ichiloto\Editor\Session\EditorSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration check for the headless map write path: `map:apply` writes a
 * batch of editor changes only when the project it leaves is valid, and
 * `validate --map` gates a change on the maps it touched.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = sys_get_temp_dir() . '/ichiloto-map-apply-' . bin2hex(random_bytes(8));

final class MapApplyFailure extends RuntimeException
{
}

/** @return array{code: int, output: string} */
function runConsoleCommand(Command $command, array $input): array
{
    $tester = new CommandTester($command);
    $code = $tester->execute($input, ['interactive' => false]);

    return ['code' => $code, 'output' => $tester->getDisplay()];
}

/** Hashes every file under a folder, by path. @return array<string, string> */
function hashProjectFiles(string $folder): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        $hashes[substr($file->getPathname(), strlen($folder))] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);

    return $hashes;
}

function writeBatch(string $projectRoot, string $name, array $changes): string
{
    $path = $projectRoot . '/' . $name . '.json';
    file_put_contents($path, json_encode(['changes' => $changes], JSON_PRETTY_PRINT));

    return $path;
}

function expectThat(bool $holds, string $failure): void
{
    if (! $holds) {
        throw new MapApplyFailure($failure);
    }
}

function removeMapApplyProject(string $root): void
{
    if (! is_dir($root)) { return; }
    $entries = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

try {
    new NewProjectScaffolder()->scaffold([
        'displayName' => 'Write Path',
        'directoryName' => 'write-path',
        'targetDirectory' => $projectRoot,
        'heroName' => 'Aria Vale',
        'heroId' => 'AriaVale',
        'battleEngine' => 'traditional',
        'titleArt' => "WRITE PATH\n",
    ]);
    $map = 'campfire-clearing';
    $layer = EditorSession::open($projectRoot)->readMap($map)['baseLayer'];
    $start = (require $projectRoot . '/assets/Data/system.php')['startingPositions']['player']['spawnPoint'];
    $assets = $projectRoot . '/assets';

    $clean = runConsoleCommand(new ValidateCommand(), ['--directory' => $projectRoot, '--map' => [$map]]);
    expectThat($clean['code'] === Command::SUCCESS, 'A clean map failed validate --map: ' . $clean['output']);
    $unknown = runConsoleCommand(new ValidateCommand(), ['--directory' => $projectRoot, '--map' => ['no-such-map']]);
    expectThat($unknown['code'] === Command::FAILURE && str_contains($unknown['output'], 'no map no-such-map'),
        'validate --map accepted a map the project does not have: ' . $unknown['output']);

    // A wall on the cell the game starts on: the player could not stand there.
    $before = hashProjectFiles($assets);
    $blocked = writeBatch($projectRoot, 'blocked', [
        ['method' => 'map.paint', 'params' => ['map' => $map, 'layer' => $layer, 'cells' => [[$start['x'], $start['y']]], 'symbol' => '#']],
    ]);
    $refused = runConsoleCommand(new MapApplyCommand(), ['batch' => $blocked, '--directory' => $projectRoot]);
    expectThat($refused['code'] === Command::FAILURE && str_contains($refused['output'], 'Refused')
        && str_contains($refused['output'], 'Nothing was written'), 'A blocked start was not refused: ' . $refused['output']);
    expectThat(hashProjectFiles($assets) === $before, 'A refused batch wrote files.');

    $json = runConsoleCommand(new MapApplyCommand(), ['batch' => $blocked, '--directory' => $projectRoot, '--json' => true]);
    $outcome = json_decode($json['output'], true);
    expectThat(is_array($outcome) && $outcome['status'] === 'refused' && $outcome['blocking'] >= 1
        && $outcome['issues'][0]['map'] === $map && $outcome['issues'][0]['severity'] === 'error',
        'The JSON outcome did not name the blocking error on its map: ' . $json['output']);

    // A wall in open floor leaves every way through, but a save may stand there: it moves only when allowed.
    $wall = writeBatch($projectRoot, 'wall', [
        ['method' => 'map.paint', 'params' => ['map' => $map, 'layer' => $layer, 'cells' => [[10, 10]], 'symbol' => '#']],
    ]);
    $unmoved = runConsoleCommand(new MapApplyCommand(), ['batch' => $wall, '--directory' => $projectRoot, '--check' => true]);
    expectThat($unmoved['code'] === Command::FAILURE && str_contains($unmoved['output'], 'allow them to move'),
        'A batch making a cell solid did not ask to move saves: ' . $unmoved['output']);
    $checked = runConsoleCommand(new MapApplyCommand(), ['batch' => $wall, '--directory' => $projectRoot, '--check' => true,
        '--relocate-saves' => true]);
    expectThat($checked['code'] === Command::SUCCESS && str_contains($checked['output'], 'nothing was written'),
        'A valid batch did not check cleanly: ' . $checked['output']);
    expectThat(hashProjectFiles($assets) === $before, 'Checking a batch wrote files.');

    $saved = runConsoleCommand(new MapApplyCommand(), ['batch' => $wall, '--directory' => $projectRoot, '--relocate-saves' => true]);
    expectThat($saved['code'] === Command::SUCCESS && str_contains($saved['output'], 'Saved ' . $map),
        'A valid batch was not saved: ' . $saved['output']);
    $rows = explode("\n", (string) file_get_contents($assets . "/Maps/{$map}/{$map}.map.php"));
    expectThat(($rows[3 + 10][10] ?? null) === '#', 'The saved map does not hold the painted wall.');

    $broken = writeBatch($projectRoot, 'broken', [['method' => 'map.paint', 'params' => ['map' => $map]]]);
    $failed = runConsoleCommand(new MapApplyCommand(), ['batch' => $broken, '--directory' => $projectRoot]);
    expectThat($failed['code'] === Command::FAILURE && str_contains($failed['output'], 'Change 1 (map.paint)'),
        'A malformed change was not named: ' . $failed['output']);

    fwrite(STDOUT, "map:apply writes valid batches only, and validate --map gates the maps named.\n");
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
} finally {
    removeMapApplyProject($projectRoot);
}
