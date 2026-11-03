<?php

declare(strict_types=1);

use Ichiloto\Console\Upgrade\ProjectUpgradeChain;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * The Engine a project installs decides the format the project must be at,
 * whatever Engine this Console carries. `play`, `edit`, `validate` and
 * `upgrade` judge a project by that Engine and say the same thing: run the
 * upgrade, update the Console when it lacks the steps, or update the
 * project's Engine when the project is newer. `upgrade` reaches exactly the
 * project's Engine's format. Each project gets a synthetic Engine whose only
 * class is the project format, so the cases do not depend on a real release.
 */

$consoleBin = dirname(__DIR__) . '/bin/ichiloto';
$latest = ProjectUpgradeChain::createDefault()->latestVersion;
$scratch = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ichiloto-format-authority-' . bin2hex(random_bytes(8));

/** @return array{exitCode: int, output: string} */
function runFormatCommand(string $consoleBin, array $arguments): array
{
    $process = proc_open(
        [PHP_BINARY, $consoleBin, ...$arguments, '--no-ansi', '--no-interaction'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'output' => preg_replace('/\s+/', ' ', $output) ?? $output];
}

/** A project at a recorded format whose installed Engine reads another; null installs an Engine without formats. */
function createFormatProject(string $root, ?int $recorded, ?int $engineFormat): string
{
    mkdir($root . DIRECTORY_SEPARATOR . 'vendor', 0777, true);
    $engine = $engineFormat === null ? "<?php\n" : <<<PHP
<?php
namespace Ichiloto\Engine\Core;
final class ProjectFormat
{
    public const string KEY = 'format';
    public const int CURRENT = {$engineFormat};
    public static function getVersion(mixed \$recorded): int { return is_int(\$recorded) && \$recorded >= 0 ? \$recorded : 0; }
}
PHP;
    file_put_contents($root . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php', $engine);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'main.php', "<?php file_put_contents(__DIR__ . '/launched', 'yes');\n");
    $config = ['id' => 'fixture/format-authority', 'main' => 'main.php'] + ($recorded === null ? [] : ['format' => $recorded]);
    file_put_contents($root . DIRECTORY_SEPARATOR . 'ichiloto.json', json_encode($config) . "\n");

    return $root;
}

function getRecordedFormat(string $root): mixed
{
    return json_decode((string) file_get_contents($root . DIRECTORY_SEPARATOR . 'ichiloto.json'), true)['format'] ?? null;
}

function removeFormatProject(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $directory . DIRECTORY_SEPARATOR . $entry;
            is_dir($path) && ! is_link($path) ? removeFormatProject($path) : unlink($path);
        }
    }
    rmdir($directory);
}

$failures = [];
$refusingCommands = static fn(string $root): array => [
    'play' => ['play', '--no-tmux', '--renderer', 'terminal', '--directory', $root],
    'edit' => ['edit', '--no-tmux', '--directory', $root],
    'validate' => ['validate', '--directory', $root],
    'upgrade' => ['upgrade', '--yes', '--directory', $root],
];

try {
    mkdir($scratch, 0777, true);
    $newerEngine = $latest + 2;
    $cases = [
        'older than an Engine this Console can reach' => [1, $latest, "This project's format (1) is older than its Engine's ({$latest}). Run `ichiloto upgrade`"],
        'older than an Engine newer than this Console' => [1, $newerEngine, "This project's format (1) is older than its Engine's ({$newerEngine}), and this Ichiloto Console only has the upgrade steps up to format {$latest}. To update the Console,"],
        'newer than its Engine' => [$latest + 1, $latest, "This project's format (" . ($latest + 1) . ") is newer than its Engine's ({$latest}). Update the project's Engine"],
        'missing format' => [null, $latest, "This project's format (0) is older than its Engine's ({$latest})."],
    ];

    foreach ($cases as $case => [$recorded, $engineFormat, $message]) {
        $root = createFormatProject($scratch . DIRECTORY_SEPARATOR . md5($case), $recorded, $engineFormat);

        foreach ($refusingCommands($root) as $command => $arguments) {
            if ($command === 'upgrade' && ($recorded ?? 0) < $engineFormat && $engineFormat <= $latest) {
                continue; // This one can upgrade; the upgrade cases below run it.
            }

            $result = runFormatCommand($consoleBin, $arguments);
            if ($result['exitCode'] === 0 || ! str_contains($result['output'], $message) || file_exists($root . DIRECTORY_SEPARATOR . 'launched')) {
                $failures[] = "{$case}: {$command} did not stop with \"{$message}\": {$result['output']}";
            }
        }

        if (getRecordedFormat($root) !== $recorded) {
            $failures[] = "{$case}: a refusing command changed the recorded format.";
        }
    }

    // Andrew's report: the Console's own Engine and steps disagreed, so upgrade said none was needed.
    $behind = createFormatProject($scratch . DIRECTORY_SEPARATOR . 'behind', 1, $newerEngine);
    $dryRun = runFormatCommand($consoleBin, ['upgrade', '--dry-run', '--directory', $behind]);
    if (str_contains($dryRun['output'], 'No upgrade is needed') || $dryRun['exitCode'] === 0) {
        $failures[] = 'upgrade claimed no upgrade was needed for a project its Engine refuses: ' . $dryRun['output'];
    }

    // The upgrade reaches the project's Engine's format: all the way, or short of this Console's latest step.
    foreach (['to the latest step' => $latest, 'to an older Engine' => 2] as $case => $engineFormat) {
        $root = createFormatProject($scratch . DIRECTORY_SEPARATOR . md5($case), 1, $engineFormat);
        $upgrade = runFormatCommand($consoleBin, ['upgrade', '--yes', '--directory', $root]);
        if ($upgrade['exitCode'] !== 0 || getRecordedFormat($root) !== $engineFormat
            || ! str_contains($upgrade['output'], "This project is at format 1; its Engine reads format {$engineFormat}.")
            || ! str_contains($upgrade['output'], "Upgraded the project to format {$engineFormat}.")
            || str_contains($upgrade['output'], 'Format ' . ($engineFormat + 1) . ',')) {
            $failures[] = "upgrade {$case} did not stop at format {$engineFormat}: " . $upgrade['output'];
            continue;
        }

        $again = runFormatCommand($consoleBin, ['upgrade', '--yes', '--directory', $root]);
        $play = runFormatCommand($consoleBin, $refusingCommands($root)['play']);
        if (! str_contains($again['output'], "No upgrade is needed; the project is already at format {$engineFormat}")
            || ! file_exists($root . DIRECTORY_SEPARATOR . 'launched')) {
            $failures[] = "after upgrading {$case}, upgrade or play still disagreed: {$again['output']} {$play['output']}";
        }
    }

    // An Engine from before project formats reads a project as it is.
    $unformatted = createFormatProject($scratch . DIRECTORY_SEPARATOR . 'unformatted', 1, null);
    $noUpgrade = runFormatCommand($consoleBin, ['upgrade', '--yes', '--directory', $unformatted]);
    runFormatCommand($consoleBin, $refusingCommands($unformatted)['play']);
    if ($noUpgrade['exitCode'] !== 0 || ! str_contains($noUpgrade['output'], 'predates project formats')
        || getRecordedFormat($unformatted) !== 1 || ! file_exists($unformatted . DIRECTORY_SEPARATOR . 'launched')) {
        $failures[] = 'a project whose Engine predates formats was upgraded or refused: ' . $noUpgrade['output'];
    }
} finally {
    if (is_dir($scratch)) {
        removeFormatProject($scratch);
    }
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: play, edit, validate and upgrade judge a project by its own Engine's format, say the same remedy, and upgrade exactly to that format.\n");
