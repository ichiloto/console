<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\PlayCommand;
use Ichiloto\Console\Support\GameLaunchCommandBuilder;
use Ichiloto\Console\Support\ProjectStartupCheck;
use Ichiloto\Console\Support\RendererPreparationProcess;
use Ichiloto\Console\Support\SourceRendererUpdateChecker;
use Ichiloto\Console\Support\TerminalSessionLauncher;
use Ichiloto\Console\Support\TerminalSessionProcess;
use Ichiloto\Console\Support\TerminalSessionWindow;
use Ichiloto\Console\Support\TerminalSessionWorker;
use Ichiloto\Engine\Core\ProjectFormat;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';

if (($argv[1] ?? '') === '--headless-worker') {
    exit(TerminalSessionWorker::run(array_slice($argv, 2), new TerminalSessionWindow(platform: 'Linux')));
}

function assertPlayStartup(bool $condition, string $message): void
{
    if (!$condition) { throw new RuntimeException($message); }
}

function removePlayStartupFixture(string $root): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

$root = sys_get_temp_dir() . '/console-play-startup-' . bin2hex(random_bytes(12));
$project = $root . "/project with 'quotes' and spaces";
mkdir($project . '/vendor', 0700, true);
mkdir($project . '/.data', 0700);
$oldStartup = getenv(GameLaunchCommandBuilder::STARTUP_ENVIRONMENT_VARIABLE);
$oldRenderer = getenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE);
putenv(GameLaunchCommandBuilder::STARTUP_ENVIRONMENT_VARIABLE);
putenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE . '=parent-renderer');
$autoload = $project . '/vendor/autoload.php';
$main = $project . '/game runner.php';
$capture = $project . '/captured.json';
$canRunPosix = PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal') && function_exists('posix_kill');
$config = json_encode(['format' => ProjectFormat::CURRENT, 'main' => basename($main),
    'audio' => ['music' => false, 'soundEffects' => false, 'voice' => false, 'masterVolume' => 0]], JSON_THROW_ON_ERROR);
file_put_contents($project . '/ichiloto.json', $config);
file_put_contents($project . '/.data/save.iedata', 'preserved normal save');
$mainSource = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/captured.json', json_encode([
    'renderer' => getenv('ICHILOTO_RENDERER'), 'start' => getenv('ICHILOTO_START_MODE'),
    'directory' => getcwd(),
], JSON_THROW_ON_ERROR));
if (($code = getenv('FIXTURE_GAME_EXIT')) !== false) { exit((int) $code); }
PHP;
file_put_contents($main, $mainSource);
$engineEnum = dirname(__DIR__, 2) . '/engine/src/Core/GameStartupMode.php';
$report = ['schema' => 'ichiloto.renderers/1', 'renderers' => [
    ['id' => 'terminal', 'displayName' => 'Native Terminal', 'available' => true, 'reason' => null],
    ['id' => 'gpui', 'displayName' => 'GPUI', 'available' => true, 'reason' => null],
], 'preferredId' => 'gpui'];
$writeAutoload = static function (array $report, bool $startupSupported = true) use ($autoload, $engineEnum): void {
    file_put_contents($autoload, '<?php namespace Ichiloto\\Engine\\Rendering\\Launch; '
        . ($startupSupported ? 'require ' . var_export($engineEnum, true) . '; ' : '')
        . 'final class RendererRegistry { public function getReport(): array { return '
        . var_export($report, true) . '; } }');
};
$windowCalls = 0;
$window = new TerminalSessionWindow(platform: 'Windows',
    commandRunner: static function (array $arguments, array $descriptors, array $options) use (&$windowCalls, $root) {
        $windowCalls++;
        assertPlayStartup($descriptors === [] && ($options['create_new_console'] ?? false), 'Terminal did not request its own window.');
        return TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'sys_temp_dir=' . $root,
            __FILE__, '--headless-worker', ...array_slice($arguments, -3)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']]);
    }, findExecutable: static fn(string $name): ?string => $name);
$terminal = new TerminalSessionLauncher($window, $root, outputWriter: static function (string $message): void {});
$tester = new CommandTester(new PlayCommand(
    rendererUpdateChecker: new SourceRendererUpdateChecker(locateEngine: static fn(string $path): string => $path),
    terminalSession: $terminal,
));
$run = static function (array $options) use ($tester, $project): int {
    return $tester->execute(['--directory' => $project, '--no-tmux' => true, ...$options], ['interactive' => false]);
};
$readCapture = static fn(): array => json_decode((string) file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);

try {
    $writeAutoload($report);
    $builder = new GameLaunchCommandBuilder();
    foreach (['title', 'new-game'] as $mode) {
        assertPlayStartup($run(['--start' => $mode, '--renderer' => 'terminal']) === Command::SUCCESS,
            'Explicit startup refused: ' . $tester->getDisplay());
        assertPlayStartup($readCapture() === ['renderer' => 'terminal', 'start' => $mode, 'directory' => $project],
            'Child lost explicit startup or project working directory.');
        if (PHP_OS_FAMILY !== 'Windows') {
            $command = $builder->buildGameCommand($main, $project . '/logs/error.log', 'terminal', $mode);
            $pipes = [];
            $process = proc_open(['/bin/sh', '-c', $command], [STDIN, STDOUT, STDERR], $pipes, $project);
            assertPlayStartup(is_resource($process) && proc_close($process) === 0 && $readCapture()['start'] === $mode,
                'The actual tmux-shaped shell command lost startup or quoted paths.');
        }
    }
    unlink($capture);
    assertPlayStartup($run(['--start' => 'continue']) === Command::INVALID && !is_file($capture),
        'Invalid startup launched a game.');
    assertPlayStartup(str_contains($tester->getDisplay(), 'title or new-game'), 'Invalid startup lacks a useful diagnostic.');
    $writeAutoload($report, false);
    assertPlayStartup($run(['--start' => 'new-game']) === Command::INVALID && !is_file($capture)
        && str_contains($tester->getDisplay(), 'does not support explicit'), 'An old Engine silently ignored startup.');
    assertPlayStartup($run(['--renderer' => 'terminal']) === Command::SUCCESS && $readCapture()['start'] === false,
        'Ordinary play no longer supports an Engine without explicit startup.');
    $writeAutoload($report);
    assertPlayStartup($run(['--start' => 'new-game', '--renderer' => 'auto', '--terminal-window' => true]) === Command::SUCCESS
        && $readCapture()['renderer'] === 'gpui' && $windowCalls === 0, 'Auto GPUI incorrectly opened a Terminal window.');
    $report['renderers'][1]['available'] = false;
    $report['renderers'][1]['reason'] = 'Not installed';
    $report['preferredId'] = 'terminal';
    $writeAutoload($report);
    assertPlayStartup($run(['--start' => 'new-game', '--renderer' => 'auto', '--terminal-window' => $canRunPosix]) === Command::SUCCESS
        && $readCapture()['renderer'] === 'terminal' && $readCapture()['start'] === 'new-game' && $windowCalls === ($canRunPosix ? 1 : 0),
        'Auto Terminal fallback did not use an owned window and explicit startup.');
    assertPlayStartup(glob($root . '/ichiloto-terminal-*') === [], 'Terminal launch left owned scratch behind.');
    assertPlayStartup($run(['--start' => 'title', '--renderer' => 'terminal', '--terminal-window' => $canRunPosix]) === Command::SUCCESS
        && $readCapture()['start'] === 'title' && $windowCalls === ($canRunPosix ? 2 : 0), 'Explicit Terminal title mode was lost.');
    assertPlayStartup($run(['--renderer' => 'gpui', '--terminal-window' => true]) === Command::SUCCESS
        && $readCapture()['renderer'] === 'gpui' && $windowCalls === ($canRunPosix ? 2 : 0), 'Explicit GPUI was silently changed to Terminal.');
    putenv('FIXTURE_GAME_EXIT=23');
    assertPlayStartup($run(['--start' => 'new-game', '--renderer' => 'terminal', '--terminal-window' => $canRunPosix]) === Command::FAILURE
        && str_contains($tester->getDisplay(), 'exited unexpectedly'), 'Terminal child failure was reported as success.');
    putenv('FIXTURE_GAME_EXIT');
    file_put_contents($autoload, '<?php echo "unexpected output";');
    try {
        new ProjectStartupCheck()->requireModeSupport($project, 'title');
        throw new RuntimeException('Invalid support output was accepted.');
    } catch (RuntimeException $error) {
        assertPlayStartup(str_contains($error->getMessage(), 'does not support explicit'), 'Unexpected support refusal.');
    }
    $writeAutoload($report);
    $process = new RendererPreparationProcess();
    $process->run([PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__) . '/bin/ichiloto', 'play',
        '--directory=' . $project, '--no-tmux', '--no-interaction', '--renderer=auto', '--start=new-game'], $root);
    assertPlayStartup($readCapture()['start'] === 'new-game' && $readCapture()['renderer'] === 'terminal',
        'The registered Play CLI did not forward the final contract.');
    assertPlayStartup(file_get_contents($project . '/ichiloto.json') === $config
        && file_get_contents($project . '/.data/save.iedata') === 'preserved normal save'
        && file_get_contents($main) === $mainSource, 'Startup rewrote settings, saves or project entrypoint.');
    assertPlayStartup(getenv(GameLaunchCommandBuilder::STARTUP_ENVIRONMENT_VARIABLE) === false
        && getenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE) === 'parent-renderer', 'Child launch changed the parent environment.');
    if (!$canRunPosix) { echo "POSIX terminal-window lifecycle fixtures skipped on this host; direct startup/selection still checked.\n"; }
    echo "Play startup and renderer/window integration tests passed.\n";
} finally {
    putenv('FIXTURE_GAME_EXIT');
    putenv($oldStartup === false ? GameLaunchCommandBuilder::STARTUP_ENVIRONMENT_VARIABLE
        : GameLaunchCommandBuilder::STARTUP_ENVIRONMENT_VARIABLE . '=' . $oldStartup);
    putenv($oldRenderer === false ? GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE
        : GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE . '=' . $oldRenderer);
    removePlayStartupFixture($root);
}
