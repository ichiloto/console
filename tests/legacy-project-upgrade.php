<?php

declare(strict_types=1);

use Ichiloto\Console\Support\SaveCompatibilityMetadata;
use Ichiloto\Engine\IO\SaveCompatibility\SaveCompatibilityManifest;

require dirname(__DIR__) . '/vendor/autoload.php';

$consoleRoot = dirname(__DIR__);
$consoleBin = $consoleRoot . '/bin/ichiloto';
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-legacy-upgrade-' . bin2hex(random_bytes(8));

/** @return array{exitCode: int, output: string} */
function runUpgradeCommand(string $consoleBin, string $consoleRoot, array $arguments): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $consoleBin, 'upgrade', '--no-ansi', ...$arguments],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $consoleRoot,
    );

    if (! is_resource($process)) {
        failUpgradeTest('Unable to start the upgrade command.');
    }

    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return [
        'exitCode' => proc_close($process),
        'output' => trim($output),
    ];
}

function writeJsonFixture(string $path, array $data): void
{
    $directory = dirname($path);

    if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
        failUpgradeTest("Unable to create fixture directory: {$directory}");
    }

    file_put_contents($path, json_encode($data, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
}

function removeUpgradeFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . DIRECTORY_SEPARATOR . $entry;

        if (is_dir($path)) {
            removeUpgradeFixture($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

function failUpgradeTest(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

try {
    $legacyRoot = $temporaryRoot . '/composer-identity';
    writeJsonFixture($legacyRoot . '/ichiloto.json', [
        'name' => 'Legacy Moon',
        'main' => 'legacy-moon.php',
    ]);
    writeJsonFixture($legacyRoot . '/composer.json', [
        'name' => 'moon-studio/legacy-moon',
    ]);

    $first = runUpgradeCommand($consoleBin, $consoleRoot, ['--directory', $legacyRoot]);

    if ($first['exitCode'] !== 0) {
        failUpgradeTest('The legacy project did not upgrade: ' . $first['output']);
    }

    $configPath = $legacyRoot . '/ichiloto.json';
    $manifestPath = $legacyRoot . '/assets/Data/save-compatibility.php';
    $config = json_decode((string) file_get_contents($configPath), true);
    $manifest = require $manifestPath;

    if (($config['id'] ?? null) !== 'moon-studio/legacy-moon' || ($config['main'] ?? null) !== 'legacy-moon.php') {
        failUpgradeTest('The upgrade did not preserve config while adopting the canonical Composer identity.');
    }

    if ($manifest !== SaveCompatibilityMetadata::baseline()) {
        failUpgradeTest('The upgrade did not create the canonical version-0 compatibility manifest.');
    }

    load_engine_autoloader($legacyRoot);
    $runtimeManifest = SaveCompatibilityManifest::fromProjectRoot($legacyRoot);

    if ($runtimeManifest->projectId !== 'moon-studio/legacy-moon' || $runtimeManifest->contentVersion !== 0) {
        failUpgradeTest('The Engine did not accept the upgraded save compatibility contract.');
    }

    $configSource = (string) file_get_contents($configPath);
    $manifestSource = (string) file_get_contents($manifestPath);
    $second = runUpgradeCommand($consoleBin, $consoleRoot, ['--directory', $legacyRoot]);

    if ($second['exitCode'] !== 0
        || ! str_contains($second['output'], 'No upgrade is needed')
        || file_get_contents($configPath) !== $configSource
        || file_get_contents($manifestPath) !== $manifestSource) {
        failUpgradeTest('The upgrade command is not idempotent.');
    }

    $dryRunRoot = $temporaryRoot . '/dry-run';
    writeJsonFixture($dryRunRoot . '/ichiloto.json', ['name' => 'Old Quest']);
    $dryRun = runUpgradeCommand($consoleBin, $consoleRoot, ['--directory', $dryRunRoot, '--dry-run']);

    if ($dryRun['exitCode'] !== 0
        || ! str_contains($dryRun['output'], 'ichiloto/old-quest')
        || array_key_exists('id', json_decode((string) file_get_contents($dryRunRoot . '/ichiloto.json'), true))
        || file_exists($dryRunRoot . '/assets/Data/save-compatibility.php')) {
        failUpgradeTest('Dry-run mode wrote files or failed to report its derived identity.');
    }

    $preservedRoot = $temporaryRoot . '/preserved';
    writeJsonFixture($preservedRoot . '/ichiloto.json', [
        'id' => 'studio/preserved-game',
        'name' => 'Preserved Game',
    ]);
    mkdir($preservedRoot . '/assets/Data', 0777, true);
    $customManifest = "<?php\n\nreturn ['contentVersion' => 7, 'custom' => true];\n";
    file_put_contents($preservedRoot . '/assets/Data/save-compatibility.php', $customManifest);
    $preserved = runUpgradeCommand($consoleBin, $consoleRoot, ['--directory', $preservedRoot]);

    if ($preserved['exitCode'] !== 0
        || file_get_contents($preservedRoot . '/assets/Data/save-compatibility.php') !== $customManifest) {
        failUpgradeTest('Existing save identity or compatibility metadata was overwritten.');
    }

    $invalidRoot = $temporaryRoot . '/invalid-id';
    writeJsonFixture($invalidRoot . '/ichiloto.json', ['name' => 'Needs Identity']);
    $invalid = runUpgradeCommand($consoleBin, $consoleRoot, [
        '--directory', $invalidRoot,
        '--id', 'Not A Stable Identity',
    ]);

    if ($invalid['exitCode'] === 0
        || array_key_exists('id', json_decode((string) file_get_contents($invalidRoot . '/ichiloto.json'), true))
        || file_exists($invalidRoot . '/assets/Data/save-compatibility.php')) {
        failUpgradeTest('An invalid requested identity did not fail closed before writing.');
    }
} finally {
    removeUpgradeFixture($temporaryRoot);
}

fwrite(STDOUT, "PASS: legacy projects gain stable, idempotent save metadata without overwriting existing contracts.\n");
