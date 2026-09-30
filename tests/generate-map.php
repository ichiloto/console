<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\GenerateMapCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';

$temporaryRoot = sys_get_temp_dir() . '/ichiloto-map-' . bin2hex(random_bytes(8));
$mapsRoot = $temporaryRoot . '/assets/Maps';
$mapDirectory = $mapsRoot . '/moonlit-glade';
$originalDirectory = getcwd() ?: dirname(__DIR__);
$paths = [
    'data' => $mapDirectory . '/moonlit-glade.data.php',
    'map' => $mapDirectory . '/moonlit-glade.map.php',
    'event' => $mapDirectory . '/moonlit-glade.event.php',
];

function removeMapFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeMapFixture($path) : unlink($path);
    }

    rmdir($directory);
}

function failMapTest(string $message): never
{
    throw new RuntimeException($message);
}

try {
    mkdir($temporaryRoot, 0755, true);
    chdir($temporaryRoot);

    $tester = new CommandTester(new GenerateMapCommand());
    $arguments = [
        'name' => 'Moonlit Glade',
        '--region' => 'Silverwood',
        '--description' => 'A clearing washed in moonlight.',
    ];
    $exitCode = $tester->execute($arguments, ['interactive' => false]);

    if ($exitCode !== Command::SUCCESS) {
        failMapTest('generate:map failed: ' . $tester->getDisplay());
    }

    foreach ($paths as $path) {
        if (! is_file($path)) {
            failMapTest("generate:map did not create {$path}.");
        }
    }

    if (is_file($temporaryRoot . '/moonlit-glade.php') || is_file($mapsRoot . '/moonlit-glade.php')) {
        failMapTest('generate:map wrote the obsolete single-file layout.');
    }

    $data = require $paths['data'];
    $tiles = require $paths['map'];
    $events = require $paths['event'];

    if (! is_array($data)
        || ($data['name'] ?? null) !== 'Moonlit Glade'
        || ($data['region'] ?? null) !== 'Silverwood'
        || ($data['description'] ?? null) !== 'A clearing washed in moonlight.'
    ) {
        failMapTest('generate:map wrote invalid map metadata.');
    }

    // A project without tilesets has no kinds, so its map has none.
    if (array_key_exists('tileset', $data) || ! str_contains($tester->getDisplay(), 'Kind: none')) {
        failMapTest('generate:map gave a kind to a map in a project without tilesets.');
    }

    if (! is_string($tiles) || ! is_string($events)) {
        failMapTest('generate:map layers must return strings.');
    }

    $tileRows = explode("\n", $tiles);
    $eventRows = explode("\n", $events);

    if (count($tileRows) !== count($eventRows)) {
        failMapTest('The generated event layer does not match the map height.');
    }

    foreach ($tileRows as $index => $tileRow) {
        if (mb_strlen($tileRow) !== mb_strlen($eventRows[$index])) {
            failMapTest('The generated event layer does not match the map width.');
        }
    }

    file_put_contents($paths['data'], '<?php return [\'sentinel\' => true];');

    if ($tester->execute($arguments, ['interactive' => false]) !== Command::FAILURE) {
        failMapTest('generate:map overwrote an existing map without --force.');
    }

    $preserved = require $paths['data'];

    if (($preserved['sentinel'] ?? false) !== true) {
        failMapTest('generate:map changed an existing map after refusing the overwrite.');
    }

    $arguments['--force'] = true;

    if ($tester->execute($arguments, ['interactive' => false]) !== Command::SUCCESS) {
        failMapTest('generate:map could not replace the complete map with --force.');
    }

    // In a project with tilesets, every new map is created with its kind.
    mkdir($temporaryRoot . '/assets/Data/Tilesets', 0755, true);
    foreach (['interior' => 'Interior', 'exterior' => 'Exterior'] as $id => $name) {
        file_put_contents($temporaryRoot . "/assets/Data/Tilesets/{$id}.php",
            "<?php\n\nreturn ['name' => '{$name}', 'sheets' => ['B' => 'Graphics/Tilesets/{$name}_B.png']];\n");
    }
    $kindDirectory = $mapsRoot . '/lantern-hall';
    $kindArguments = ['name' => 'Lantern Hall', '--region' => '', '--description' => ''];

    if ($tester->execute($kindArguments, ['interactive' => false]) !== Command::FAILURE
        || ! str_contains($tester->getDisplay(), "Name the map's kind with --kind: exterior, interior.")
        || is_dir($kindDirectory)) {
        failMapTest('generate:map created a map without a kind in a project that has tilesets.');
    }

    if ($tester->execute([...$kindArguments, '--kind' => 'cavern'], ['interactive' => false]) !== Command::FAILURE
        || ! str_contains($tester->getDisplay(), "cavern is not one of the project's kinds: exterior, interior.")
        || is_dir($kindDirectory)) {
        failMapTest('generate:map accepted a kind the project does not have.');
    }

    if ($tester->execute([...$kindArguments, '--kind' => 'interior'], ['interactive' => false]) !== Command::SUCCESS
        || ((require $kindDirectory . '/lantern-hall.data.php')['tileset'] ?? null) !== 'interior'
        || ! str_contains($tester->getDisplay(), 'Kind: Interior')) {
        failMapTest('generate:map did not create the map with the kind it was given: ' . $tester->getDisplay());
    }

    fwrite(STDOUT, "PASS: generate:map writes the complete Engine 0.5 map layout, with its kind.\n");
} catch (Throwable $throwable) {
    fwrite(STDERR, "FAIL: {$throwable->getMessage()}\n");
    exit(1);
} finally {
    chdir($originalDirectory);
    removeMapFixture($temporaryRoot);
}
