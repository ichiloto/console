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

function failScaffolderTest(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
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
    $project = json_decode((string) file_get_contents($projectConfigPath), true);
    $composer = json_decode((string) file_get_contents($composerPath), true);
    $inputSource = (string) file_get_contents($inputPath);
    $manifest = require $manifestPath;

    if (($project['id'] ?? null) !== 'ichiloto/save-ready-project') {
        failScaffolderTest('The generated project has no deterministic stable save identity.');
    }

    if (($composer['name'] ?? null) !== $project['id']) {
        failScaffolderTest('The generated Composer package and project save identities differ.');
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

    if (str_contains($inputSource, "'notify' =>")) {
        failScaffolderTest('New projects expose the development notification action to players.');
    }

    if (! str_contains($inputSource, "'skit' =>") || ! str_contains($inputSource, '[KeyCode::T, KeyCode::t]')) {
        failScaffolderTest('New projects do not expose the supported skit action.');
    }
} finally {
    removeScaffoldedProject($projectRoot);
}

fwrite(STDOUT, "PASS: new projects include stable identity and save compatibility metadata.\n");
