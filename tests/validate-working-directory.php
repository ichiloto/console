<?php

declare(strict_types=1);
require_once dirname(__DIR__) . '/vendor/autoload.php';
require_once __DIR__ . '/fixtures/IntegrationProject.php';

use Ichiloto\Console\Tests\Fixtures\IntegrationProject;

/**
 * Integration check for the production validation command.
 */

$consoleRoot = dirname(__DIR__);
$consoleBin = $consoleRoot . '/bin/ichiloto';
$projectRoot = IntegrationProject::createProject('validation-');

/**
 * Returns the path to one directory relative to another, so the "relative
 * target" case reaches the project wherever it is pinned.
 */
function relativePath(string $from, string $to): string
{
    $fromParts = explode('/', trim($from, '/'));
    $toParts = explode('/', trim($to, '/'));

    while ($fromParts !== [] && $toParts !== [] && $fromParts[0] === $toParts[0]) {
        array_shift($fromParts);
        array_shift($toParts);
    }

    return implode('/', [...array_fill(0, count($fromParts), '..'), ...$toParts]) ?: '.';
}

/**
 * @return array{exitCode: int, output: string}
 */
function validationReport(string $consoleBin, string $workingDirectory, string $target): array
{
    $pipes = [];
    $process = proc_open(
        [PHP_BINARY, '-d', 'memory_limit=' . ini_get('memory_limit'), $consoleBin, 'validate', '--no-ansi', '--directory', $target],
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

/** Fails verification; the shared process fixture owner performs cleanup. */
final class TestFailure extends Exception
{
}

function fail(string $message): never
{
    throw new TestFailure($message);
}

try {
    $baseline = validationReport($consoleBin, $projectRoot, '.');

    if ($baseline['exitCode'] !== 0
        || (! str_contains($baseline['output'], 'Console Fixture looks good.')
            && ! str_contains($baseline['output'], '0 errors,'))) {
        fail('The synthetic project-root validation baseline did not pass: ' . $baseline['output']);
    }
} catch (TestFailure $failure) {
    fwrite(STDERR, "FAIL: {$failure->getMessage()}\n");
    exit(1);
}

$reports = [
    'Console cwd with relative target' => validationReport($consoleBin, $consoleRoot, relativePath($consoleRoot, $projectRoot)),
    'Console cwd with absolute target' => validationReport($consoleBin, $consoleRoot, $projectRoot),
    'Unrelated cwd with absolute target' => validationReport($consoleBin, \getConsoleTestTemporaryDirectory(), $projectRoot),
];

try {
    foreach ($reports as $context => $report) {
        assertSameReport($baseline, $report, $context);
    }
} catch (TestFailure $failure) {
    fwrite(STDERR, "FAIL: {$failure->getMessage()}\n");
    exit(1);
}

try {
    $missingEnemy = 'Definitely Missing Enemy';
    IntegrationProject::setQuestTarget($projectRoot, $missingEnemy);
    $negative = validationReport($consoleBin, $consoleRoot, $projectRoot);

    if ($negative['exitCode'] === 0 || ! str_contains($negative['output'], $missingEnemy)) {
        fail('A genuine missing enemy reference was not reported: ' . $negative['output']);
    }
} catch (TestFailure $failure) {
    $testFailure = $failure->getMessage();
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

// An optional real-project check reads current content without copying it.
try {
    $pinnedGame = getenv('ICHILOTO_GAME_SRC');
    if (is_string($pinnedGame) && $pinnedGame !== '') {
        $live = realpath($pinnedGame);
        if ($live === false || !is_dir($live . '/assets')) {
            fail('ICHILOTO_GAME_SRC does not name a project.');
        }
        $liveBaseline = validationReport($consoleBin, $live, '.');
        if ($liveBaseline['exitCode'] !== 0) {
            fail('The pinned project failed validation: ' . $liveBaseline['output']);
        }
        foreach ([$live, relativePath($consoleRoot, $live)] as $target) {
            assertSameReport($liveBaseline, validationReport($consoleBin, $consoleRoot, $target), 'Pinned project cwd');
        }
        assertSameReport($liveBaseline, validationReport($consoleBin, \getConsoleTestTemporaryDirectory(), $live), 'Pinned project unrelated cwd');
    }
} catch (TestFailure $failure) {
    fwrite(STDERR, "FAIL: {$failure->getMessage()}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: validation is cwd-independent and genuine missing references still fail.\n");
