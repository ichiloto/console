<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\ProjectFormat;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * `ichiloto play` and `ichiloto validate` stop on a project whose format is
 * not the engine's, print the engine's own explanation (which points to
 * `ichiloto upgrade`), and never launch the game or read its data.
 */

$consoleBin = dirname(__DIR__) . '/bin/ichiloto';
$projectRoot = sys_get_temp_dir() . '/ichiloto-format-refusal-' . bin2hex(random_bytes(8));

/** @return array{exitCode: int, output: string} */
function runFormatCommand(string $consoleBin, array $arguments): array
{
    $process = proc_open(
        [PHP_BINARY, $consoleBin, ...$arguments],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']],
        $pipes,
    );
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'output' => $output];
}

function removeFormatProject(string $directory): void
{
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry !== '.' && $entry !== '..') {
            $path = $directory . '/' . $entry;
            is_dir($path) ? removeFormatProject($path) : unlink($path);
        }
    }
    rmdir($directory);
}

$failures = [];

try {
    mkdir($projectRoot . '/vendor', 0777, true);
    file_put_contents($projectRoot . '/vendor/autoload.php', "<?php\n");
    file_put_contents($projectRoot . '/main.php', "<?php file_put_contents(__DIR__ . '/launched', 'yes');\n");

    foreach (['missing' => null, 'older' => ProjectFormat::CURRENT - 1, 'newer' => ProjectFormat::CURRENT + 1] as $case => $format) {
        $config = ['id' => 'fixture/format-refusal', 'main' => 'main.php'] + ($format === null ? [] : [ProjectFormat::KEY => $format]);
        file_put_contents($projectRoot . '/ichiloto.json', json_encode($config) . "\n");

        try {
            ProjectFormat::assertSupported($format);
            $failures[] = "{$case}: the engine accepted the format.";
            continue;
        } catch (Throwable $exception) {
            $message = $exception->getMessage();
        }

        foreach (['play' => ['play', '--no-ansi', '--no-tmux', '--directory', $projectRoot], 'validate' => ['validate', '--no-ansi', '--directory', $projectRoot]] as $command => $arguments) {
            $result = runFormatCommand($consoleBin, $arguments);
            if ($result['exitCode'] === 0 || ! str_contains($result['output'], $message) || file_exists($projectRoot . '/launched')) {
                $failures[] = "{$case} {$command} did not stop with the engine's format message: {$result['output']}";
            }
        }
    }

    file_put_contents($projectRoot . '/ichiloto.json', json_encode(['id' => 'fixture/format-refusal', 'main' => 'main.php', ProjectFormat::KEY => ProjectFormat::CURRENT]) . "\n");
    $current = runFormatCommand($consoleBin, ['play', '--no-ansi', '--no-tmux', '--renderer', 'terminal', '--directory', $projectRoot]);
    if (! file_exists($projectRoot . '/launched')) {
        $failures[] = 'play did not launch a project at the current format: ' . $current['output'];
    }
} finally {
    removeFormatProject($projectRoot);
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: play and validate stop with the engine's format message before reading an outdated or newer project.\n");
