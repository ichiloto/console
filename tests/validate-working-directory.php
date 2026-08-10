<?php

declare(strict_types=1);

/**
 * Cross-repository integration check for the production validation command.
 *
 * This intentionally exercises the real Last Legend project in the sibling
 * workspace. The negative case works on a disposable project copy.
 */

$consoleRoot = dirname(__DIR__);
$workspaceRoot = dirname($consoleRoot);
$projectRoot = realpath($workspaceRoot . '/examples/last-legend');
$consoleBin = $consoleRoot . '/bin/ichiloto';
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-validation-' . bin2hex(random_bytes(8));

if (! is_string($projectRoot)) {
    fail('The sibling Last Legend worktree was not found.');
}

/**
 * @return array{exitCode: int, output: string}
 */
function validationReport(string $consoleBin, string $workingDirectory, string $target): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, $consoleBin, 'validate', '--no-ansi', '--directory', $target],
        [
            0 => ['pipe', 'r'],
            1 => ['pipe', 'w'],
            2 => ['pipe', 'w'],
        ],
        $pipes,
        $workingDirectory,
    );

    if (! is_resource($process)) {
        fail('Unable to start the validation command.');
    }

    fclose($pipes[0]);
    $stdout = stream_get_contents($pipes[1]);
    $stderr = stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    $exitCode = proc_close($process);

    return [
        'exitCode' => $exitCode,
        'output' => trim((string) $stdout . (string) $stderr),
    ];
}

function assertSameReport(array $expected, array $actual, string $context): void
{
    if ($expected !== $actual) {
        fail(sprintf(
            "%s produced a different validation report.\nExpected: %s\nActual: %s",
            $context,
            var_export($expected, true),
            var_export($actual, true),
        ));
    }
}

function copyDirectory(string $source, string $destination): void
{
    if (! is_dir($destination) && ! mkdir($destination, 0777, true) && ! is_dir($destination)) {
        fail("Unable to create temporary directory: {$destination}");
    }

    foreach (scandir($source) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $sourcePath = $source . '/' . $entry;
        $destinationPath = $destination . '/' . $entry;

        if (is_dir($sourcePath)) {
            copyDirectory($sourcePath, $destinationPath);
            continue;
        }

        if (! copy($sourcePath, $destinationPath)) {
            fail("Unable to copy fixture file: {$sourcePath}");
        }
    }
}

function removeDirectory(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }

    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }

        $path = $directory . '/' . $entry;

        if (is_dir($path)) {
            removeDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

function fail(string $message): never
{
    fwrite(STDERR, "FAIL: {$message}\n");
    exit(1);
}

$baseline = validationReport($consoleBin, $projectRoot, '.');

if ($baseline['exitCode'] !== 0 || ! str_contains($baseline['output'], 'Last Legend looks good.')) {
    fail('The Last Legend project-root validation baseline did not pass: ' . $baseline['output']);
}

$reports = [
    'Console cwd with relative target' => validationReport($consoleBin, $consoleRoot, '../examples/last-legend'),
    'Console cwd with absolute target' => validationReport($consoleBin, $consoleRoot, $projectRoot),
    'Unrelated cwd with absolute target' => validationReport($consoleBin, sys_get_temp_dir(), $projectRoot),
];

foreach ($reports as $context => $report) {
    assertSameReport($baseline, $report, $context);
}

try {
    mkdir($temporaryRoot, 0777, true);
    copy($projectRoot . '/ichiloto.json', $temporaryRoot . '/ichiloto.json');
    copyDirectory($projectRoot . '/assets', $temporaryRoot . '/assets');

    $questPath = $temporaryRoot . '/assets/Data/quests.php';
    $quests = (string) file_get_contents($questPath);
    $missingEnemy = 'Definitely Missing Enemy';
    $brokenQuests = str_replace("'target' => 'Sewer Rat'", "'target' => '{$missingEnemy}'", $quests, $count);

    if ($count !== 1) {
        fail('The negative fixture could not replace its expected enemy reference.');
    }

    file_put_contents($questPath, $brokenQuests);
    $negative = validationReport($consoleBin, $consoleRoot, $temporaryRoot);

    if ($negative['exitCode'] === 0 || ! str_contains($negative['output'], $missingEnemy)) {
        fail('A genuine missing enemy reference was not reported: ' . $negative['output']);
    }
} finally {
    removeDirectory($temporaryRoot);
}

fwrite(STDOUT, "PASS: validation is cwd-independent and genuine missing references still fail.\n");
