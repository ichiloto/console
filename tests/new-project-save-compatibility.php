<?php

declare(strict_types=1);

use Ichiloto\Console\Support\NewProjectScaffolder;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = sys_get_temp_dir() . '/ichiloto-new-project-' . bin2hex(random_bytes(8));
$scaffolder = new NewProjectScaffolder();

/** Removes only this test's unique disposable project directory. */
function removeScaffoldedProject(string $directory): void
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
            removeScaffoldedProject($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

/**
 * A failed expectation. Thrown rather than exited on, so the scaffolded
 * project is removed by the finally below whether the run passes or fails.
 */
final class TestFailure extends Exception
{
}

function failScaffolderTest(string $message): never
{
    throw new TestFailure($message);
}

/** @return array{code: int, output: string} */
function validateScaffoldedProject(string $projectRoot, array $options = []): array
{
    $command = [PHP_BINARY, dirname(__DIR__) . '/bin/ichiloto', 'validate', '--no-ansi', '--no-interaction', '--directory', $projectRoot, ...$options];
    $process = proc_open($command, [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, dirname(__DIR__));
    if (! is_resource($process)) {
        failScaffolderTest('Could not start project validation.');
    }

    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['code' => proc_close($process), 'output' => $output];
}

try {
    $scaffolder->ensureTargetIsAvailable($projectRoot);
    $result = $scaffolder->scaffold([
        'displayName' => 'Save Ready Project',
        'directoryName' => 'save-ready-project',
        'targetDirectory' => $projectRoot,
        'heroName' => 'Hero',
        'heroId' => 'Hero',
        'battleEngine' => 'traditional',
        'titleArt' => "SAVE READY\n",
    ]);

    $projectConfigPath = $projectRoot . '/ichiloto.json';
    $composerPath = $projectRoot . '/composer.json';
    $inputPath = $projectRoot . '/input.php';
    $manifestPath = $projectRoot . '/assets/Data/save-compatibility.php';
    $heroPath = $projectRoot . '/assets/Data/Actors/Hero.php';
    $project = json_decode((string) file_get_contents($projectConfigPath), true);
    $composer = json_decode((string) file_get_contents($composerPath), true);
    $inputSource = (string) file_get_contents($inputPath);
    $manifest = require $manifestPath;
    $hero = require $heroPath;
    $system = require $projectRoot . '/assets/Data/system.php';
    $ignore = (string) file_get_contents($projectRoot . '/.gitignore');

    if (($project['id'] ?? null) !== 'ichiloto/save-ready-project') {
        failScaffolderTest('The generated project has no deterministic stable save identity.');
    }

    if (($composer['name'] ?? null) !== $project['id']) {
        failScaffolderTest('The generated Composer package and project save identities differ.');
    }

    if (($composer['require']['ichiloto/engine'] ?? null) !== '^0.5') {
        failScaffolderTest('The generated project does not target Ichiloto Engine 0.5.');
    }

    if ($manifest !== [
        'contentVersion' => 0,
        'migrations' => [],
        'aliases' => [],
        'tombstones' => [],
    ]) {
        failScaffolderTest('The generated save compatibility manifest is invalid.');
    }

    if (! in_array($manifestPath, $result['files'], true)) {
        failScaffolderTest('The generated-file report omits the save compatibility manifest.');
    }

    if (($hero['data']['id'] ?? null) !== ($hero['data']['name'] ?? null)) {
        failScaffolderTest('The starter hero does not declare the original display name as a stable id.');
    }

    if (($system['startingParty'][0] ?? null) !== ($hero['data']['id'] ?? null)) {
        failScaffolderTest('The starting party does not reference the hero by its declared stable id.');
    }

    if (! str_contains($ignore, '/.data/player-settings.json')) {
        failScaffolderTest('The generated project does not ignore machine-local player settings.');
    }

    $freshValidation = validateScaffoldedProject($projectRoot);
    if ($freshValidation['code'] !== 0) {
        failScaffolderTest('The freshly scaffolded project does not validate: ' . $freshValidation['output']);
    }

    $legacySource = str_replace("    'id' => 'Hero',\n", '', (string) file_get_contents($heroPath), $removedIds);
    if ($removedIds !== 1) {
        failScaffolderTest('The test could not create a legacy actor without an id.');
    }
    $legacySource = str_replace("    'name' => 'Hero',", "    // Keep this authored comment.\n    'name' => 'Hero',", $legacySource);
    file_put_contents($heroPath, $legacySource);

    $legacyValidation = validateScaffoldedProject($projectRoot);
    if ($legacyValidation['code'] === 0
        || ! str_contains($legacyValidation['output'], 'Actor has no explicit stable id')
        || ! str_contains($legacyValidation['output'], '--migrate-actor-ids')
        || file_get_contents($heroPath) !== $legacySource) {
        failScaffolderTest('Validation did not report a legacy actor without changing its source. ' . $legacyValidation['output']);
    }

    $migration = validateScaffoldedProject($projectRoot, ['--migrate-actor-ids']);
    $migratedSource = (string) file_get_contents($heroPath);
    $migratedHero = require $heroPath;
    if ($migration['code'] !== 0
        || ! str_contains($migration['output'], 'Added stable ids to 1 actor(s)')
        || ($migratedHero['data']['id'] ?? null) !== 'Hero'
        || ! str_contains($migratedSource, '// Keep this authored comment.')) {
        failScaffolderTest('Explicit migration did not preserve authored source and identity. ' . $migration['output']);
    }

    $secondMigration = validateScaffoldedProject($projectRoot, ['--migrate-actor-ids']);
    if ($secondMigration['code'] !== 0 || file_get_contents($heroPath) !== $migratedSource) {
        failScaffolderTest('The actor id migration was not idempotent. ' . $secondMigration['output']);
    }

    $malformedSource = str_replace("'id' => 'Hero'", "'id' => ''", $migratedSource);
    file_put_contents($heroPath, $malformedSource);
    $malformed = validateScaffoldedProject($projectRoot, ['--migrate-actor-ids']);
    if ($malformed['code'] === 0 || file_get_contents($heroPath) !== $malformedSource) {
        failScaffolderTest('An authored empty actor id was silently repaired. ' . $malformed['output']);
    }

    if (str_contains($inputSource, "'notify' =>")) {
        failScaffolderTest('New projects expose the development notification action to players.');
    }

    if (! str_contains($inputSource, "'skit' =>") || ! str_contains($inputSource, '[KeyCode::T, KeyCode::t]')) {
        failScaffolderTest('New projects do not expose the supported skit action.');
    }
} catch (TestFailure $failure) {
    $testFailure = $failure->getMessage();
} finally {
    removeScaffoldedProject($projectRoot);
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: new projects include stable identity and save compatibility metadata.\n");
