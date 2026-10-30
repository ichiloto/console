<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\MapRenderCommand;
use Ichiloto\Console\Support\NewProjectScaffolder;
use Ichiloto\Editor\Session\EditorSession;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration check for `map:render`: a map renders both ways into one review
 * page, a region and a player on a cell are honoured, and a batch's changes
 * render before they are written, without writing them.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = sys_get_temp_dir() . '/ichiloto-map-render-' . bin2hex(random_bytes(8));
$out = $projectRoot . '-review';

final class MapRenderFailure extends RuntimeException
{
}

/** @return array{code: int, output: string} */
function runRenderCommand(array $input): array
{
    $tester = new CommandTester(new MapRenderCommand());
    $code = $tester->execute($input, ['interactive' => false]);

    return ['code' => $code, 'output' => $tester->getDisplay()];
}

/** Hashes every file under a folder, by path. @return array<string, string> */
function hashRenderedProject(string $folder): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        $hashes[substr($file->getPathname(), strlen($folder))] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);

    return $hashes;
}

function expectRendered(bool $holds, string $failure): void
{
    if (! $holds) {
        throw new MapRenderFailure($failure);
    }
}

function removeRenderTree(string $root): void
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
        'displayName' => 'Review Path',
        'directoryName' => 'review-path',
        'targetDirectory' => $projectRoot,
        'heroName' => 'Aria Vale',
        'heroId' => 'AriaVale',
        'battleEngine' => 'traditional',
        'titleArt' => "REVIEW PATH\n",
    ]);
    $map = 'campfire-clearing';
    $layer = EditorSession::open($projectRoot)->readMap($map)['baseLayer'];
    $start = (require $projectRoot . '/assets/Data/system.php')['startingPositions']['player']['spawnPoint'];
    $assets = $projectRoot . '/assets';
    $before = hashRenderedProject($assets);

    $rendered = runRenderCommand(['views' => [$map, "{$map}@8,8,6,4"], '--directory' => $projectRoot, '--out' => $out,
        '--player' => "{$start['x']},{$start['y']},north", '--scale' => '0.25', '--json' => true]);
    $outcome = json_decode($rendered['output'], true);
    expectRendered($rendered['code'] === Command::SUCCESS && is_array($outcome) && $outcome['status'] === 'rendered'
        && count($outcome['views']) === 2, 'Two views did not render: ' . $rendered['output']);
    foreach ($outcome['views'] as $view) {
        foreach (['terminal-ans', 'terminal-html', 'png', 'scaled-png'] as $kind) {
            expectRendered(is_file($view['files'][$kind] ?? ''), sprintf('%s wrote no %s file.', $view['view'], $kind));
        }
    }
    $region = getimagesize($outcome['views'][1]['files']['png']);
    expectRendered($region[0] === 6 * 48 && $region[1] === 4 * 48, 'The region still is not 6 by 4 field cells.');
    $page = (string) file_get_contents($outcome['review']);
    expectRendered(str_contains($page, $map) && str_contains($page, "{$map}, x 8, y 8, 6 by 4")
        && substr_count($page, '<pre class="terminal">') === 2, 'The review page does not show both views.');
    $rows = explode("\n", (string) file_get_contents($outcome['views'][0]['files']['terminal-ans']));
    $glyph = mb_substr(preg_replace('/\e\[[0-9;]*m/', '', $rows[$start['y']]) ?? '', $start['x'], 1);
    $north = (require $assets . '/Data/Entities/player.php')['sprites']['north'][0] ?? null;
    expectRendered($glyph === $north, sprintf('The player does not stand on the start facing north: %s.', $glyph));
    expectRendered(hashRenderedProject($assets) === $before, 'Rendering wrote into the project.');

    // A batch's changes render before they are written, and are never written.
    $batch = $projectRoot . '/wall.json';
    file_put_contents($batch, json_encode(['changes' => [
        ['method' => 'map.paint', 'params' => ['map' => $map, 'layer' => $layer, 'cells' => [[10, 10]], 'symbol' => 'W']],
    ]]));
    $previewed = runRenderCommand(['views' => ["{$map}@8,8,6,4"], '--directory' => $projectRoot, '--out' => $out . '/batch',
        '--changes' => $batch]);
    $rows = explode("\n", (string) file_get_contents($out . "/batch/{$map}@8,8,6,4.terminal.ans"));
    expectRendered($previewed['code'] === Command::SUCCESS && str_contains($previewed['output'], 'nothing was written')
        && mb_substr(preg_replace('/\e\[[0-9;]*m/', '', $rows[2]) ?? '', 2, 1) === 'W',
        'The batch did not render its wall at (10, 10): ' . $previewed['output']);
    expectRendered(hashRenderedProject($assets) === $before, 'Rendering a batch wrote it into the project.');

    $refused = runRenderCommand(['views' => ["{$map}@0,0,500,4"], '--directory' => $projectRoot, '--out' => $out . '/refused']);
    expectRendered($refused['code'] === Command::FAILURE && str_contains($refused['output'], 'runs off map'),
        'A region off the map was not refused: ' . $refused['output']);

    fwrite(STDOUT, "map:render renders maps both ways for review, and batches before they are written.\n");
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    exit(1);
} finally {
    removeRenderTree($projectRoot);
    removeRenderTree($out);
}
