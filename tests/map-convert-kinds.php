<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\MapConvertKindsCommand;
use Ichiloto\Console\Support\NewProjectScaffolder;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

/**
 * Integration check for converting a glyph-keyed map to terrain kinds:
 * `map:convert-kinds` lists the map's glyphs and the kinds without a
 * mapping, previews counts and collision changes, writes nothing when only
 * checking, and converts through the editor's validated change set.
 * Synthetic kinds on a freshly scaffolded project only.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = sys_get_temp_dir() . '/ichiloto-convert-kinds-' . bin2hex(random_bytes(8));

final class ConvertKindsFailure extends RuntimeException
{
}

/** @return array{code: int, output: string} */
function runConvertKinds(array $input): array
{
    $tester = new CommandTester(new MapConvertKindsCommand());
    $code = $tester->execute($input, ['interactive' => false]);

    return ['code' => $code, 'output' => $tester->getDisplay()];
}

/** Hashes every file under a folder, by path. @return array<string, string> */
function hashConvertKindsFiles(string $folder): array
{
    $hashes = [];
    foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($folder, FilesystemIterator::SKIP_DOTS)) as $file) {
        $hashes[substr($file->getPathname(), strlen($folder))] = hash_file('sha256', $file->getPathname());
    }
    ksort($hashes);

    return $hashes;
}

function expectConversion(bool $holds, string $failure): void
{
    if (! $holds) {
        throw new ConvertKindsFailure($failure);
    }
}

function removeConvertKindsProject(string $root): void
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
        'displayName' => 'Kinds',
        'directoryName' => 'kinds',
        'targetDirectory' => $projectRoot,
        'heroName' => 'Aria Vale',
        'heroId' => 'AriaVale',
        'battleEngine' => 'traditional',
        'titleArt' => "KINDS\n",
    ]);
    $map = 'campfire-clearing';
    $assets = $projectRoot . '/assets';
    mkdir($assets . '/Data/Tilesets', 0o777, true);
    file_put_contents($assets . '/Data/Tilesets/field.php', '<?php return ' . var_export(['name' => 'Field',
        'sheets' => ['A2' => 'Graphics/Tilesets/Field_A2.png'], 'kinds' => [
            'grassland' => ['name' => 'Grassland', 'layer' => 'terrain', 'glyph' => '<fg=green>.</>', 'art' => ['awaiting' => true],
                'passage' => 'walk', 'encounters' => 'grassland', 'atlas' => 'ground'],
            'cliff' => ['name' => 'Cliff', 'layer' => 'terrain', 'glyph' => '<fg=gray>#</>', 'art' => ['awaiting' => true],
                'passage' => 'solid', 'atlas' => 'obstacle'],
        ]], true) . ';');
    $data = $assets . "/Maps/{$map}/{$map}.data.php";
    file_put_contents($data, preg_replace('/return (\[|array \()/', "return \$1\n  'tileset' => 'field',", (string) file_get_contents($data), 1));
    // The scaffold's save point glyph has no tiles in this synthetic tileset; the clearing is open ground here.
    $grid = $assets . "/Maps/{$map}/{$map}.map.php";
    file_put_contents($grid, preg_replace('/\?(?!php)/', ' ', (string) file_get_contents($grid)));
    $before = hashConvertKindsFiles($assets);

    $listed = runConvertKinds(['map' => $map, '--directory' => $projectRoot]);
    expectConversion($listed['code'] === Command::SUCCESS && str_contains($listed['output'], 'not painted in terrain kinds yet')
        && str_contains($listed['output'], "'#' on terrain") && str_contains($listed['output'], 'names a kind: map it')
        && str_contains($listed['output'], 'grassland (Grassland) walk, awaiting art'),
        'Listing without a mapping did not show the glyphs and kinds: ' . $listed['output']);

    $refused = runConvertKinds(['map' => $map, '--directory' => $projectRoot, '--kind' => [' =grassland']]);
    expectConversion($refused['code'] === Command::FAILURE && str_contains($refused['output'], 'which name terrain kinds'),
        'A conversion leaving a kind glyph unmapped was not refused: ' . $refused['output']);

    $checked = runConvertKinds(['map' => $map, '--directory' => $projectRoot, '--kind' => [' =grassland', '#=cliff'], '--check' => true]);
    expectConversion($checked['code'] === Command::SUCCESS && preg_match("/' ' -> Grassland: \\d+ cells/", $checked['output']) === 1
        && str_contains($checked['output'], 'nothing was written'), 'A valid conversion did not check cleanly: ' . $checked['output']);
    expectConversion(hashConvertKindsFiles($assets) === $before, 'Checking a conversion wrote files.');

    $saved = runConvertKinds(['map' => $map, '--directory' => $projectRoot, '--kind' => [' =grassland', '#=cliff']]);
    expectConversion($saved['code'] === Command::SUCCESS && str_contains($saved['output'], 'Saved ' . $map),
        'A valid conversion was not saved: ' . $saved['output']);
    $converted = require $data;
    $saved = (string) file_get_contents($grid);
    expectConversion(($converted['terrainKinds'] ?? null) === true && str_contains($saved, '<fg=green>..') && ! str_contains($saved, '                '),
        'The saved map is not painted in its kinds.');

    fwrite(STDOUT, "map:convert-kinds previews, checks and converts maps to terrain kinds through validated change sets.\n");
} catch (Throwable $failure) {
    fwrite(STDERR, $failure->getMessage() . PHP_EOL);
    $failed = true;
} finally {
    // Exiting from the catch would skip this; the project goes first, then the status.
    removeConvertKindsProject($projectRoot);
}
exit(isset($failed) ? 1 : 0);
