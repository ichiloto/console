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
    if (getenv('ICHILOTO_LAUNCH_CONTEXT') !== false) { exit(124); }
    exit(TerminalSessionWorker::run(array_slice($argv, 2), new TerminalSessionWindow(platform: 'Linux')));
}

$assertions = 0;
function assertLaunchContext(bool $condition, string $message): void
{
    global $assertions;
    $assertions++;
    if (!$condition) { throw new RuntimeException($message); }
}

function removeLaunchContextFixture(string $root): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}

/** @return array{code: int, output: string} */
function runLaunchContextTmuxCli(string $project, string $json): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'sys_temp_dir=' . sys_get_temp_dir(),
        dirname(__DIR__) . '/bin/ichiloto', 'play', '--directory=' . $project, '--renderer=terminal',
        '--no-interaction', '--launch-context=' . $json],
        [0 => ['pty'], 1 => ['pty'], 2 => ['pipe', 'w']], $pipes, $project);
    if (!is_resource($process)) { throw new RuntimeException('Could not start the synthetic tmux CLI.'); }
    fclose($pipes[0]);
    stream_set_blocking($pipes[1], false);
    stream_set_blocking($pipes[2], false);
    $output = '';
    $deadline = microtime(true) + 10;
    try {
        do {
            $output .= (string) @stream_get_contents($pipes[1]);
            $output .= (string) stream_get_contents($pipes[2]);
            $status = proc_get_status($process);
            if (!$status['running']) { break; }
            if (microtime(true) >= $deadline) { throw new RuntimeException('Synthetic tmux CLI timed out.'); }
            usleep(10000);
        } while (true);
        $output .= (string) @stream_get_contents($pipes[1]);
        $output .= (string) stream_get_contents($pipes[2]);
    } finally {
        if (proc_get_status($process)['running']) { proc_terminate($process, 9); }
        fclose($pipes[1]);
        fclose($pipes[2]);
        $code = proc_close($process);
    }
    return ['code' => $code < 0 ? $status['exitcode'] : $code, 'output' => $output];
}

$root = sys_get_temp_dir() . '/console-launch-context-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$root = realpath($root);
$project = $root . "/project with 'quotes' and spaces";
$dataRoot = $root . "/isolated 'data'";
mkdir($project . '/vendor', 0700, true);
mkdir($project . '/.data', 0700);
mkdir($dataRoot, 0700);
$environmentNames = ['ICHILOTO_START_MODE', 'ICHILOTO_LAUNCH_CONTEXT', 'ICHILOTO_RENDERER', 'TMUX', 'PATH', 'FIXTURE_GAME_EXIT'];
$savedEnvironment = [];
foreach ($environmentNames as $name) {
    $savedEnvironment[$name] = getenv($name);
    if ($name !== 'PATH') { putenv($name); }
}
putenv('ICHILOTO_RENDERER=parent-renderer');
$autoload = $project . '/vendor/autoload.php';
$consoleAutoload = dirname(__DIR__) . '/vendor/autoload.php';
$autoloadSource = '<?php require ' . var_export($consoleAutoload, true) . '; '
    . 'if (getenv("ICHILOTO_LAUNCH_CONTEXT") !== false) { throw new RuntimeException("Context leaked to probe"); }';
file_put_contents($autoload, $autoloadSource);
$main = $project . '/game runner.php';
$capture = $project . '/captured.json';
$mainSource = <<<'PHP'
<?php
file_put_contents(__DIR__ . '/captured.json', json_encode([
    'context' => getenv('ICHILOTO_LAUNCH_CONTEXT'), 'start' => getenv('ICHILOTO_START_MODE'),
    'renderer' => getenv('ICHILOTO_RENDERER'), 'directory' => getcwd(),
    'errorLog' => ini_get('error_log'), 'diagnostic' => getenv('ICHILOTO_TERMINAL_DIAGNOSTIC_LOG'),
], JSON_THROW_ON_ERROR));
fwrite(STDERR, "synthetic game stderr\n");
error_log('synthetic PHP error_log');
if (($code = getenv('FIXTURE_GAME_EXIT')) !== false) { exit((int) $code); }
PHP;
file_put_contents($main, $mainSource);
$config = json_encode(['format' => ProjectFormat::CURRENT, 'main' => basename($main),
    'audio' => ['music' => false, 'soundEffects' => false, 'voice' => false, 'masterVolume' => 0]], JSON_THROW_ON_ERROR);
file_put_contents($project . '/ichiloto.json', $config);
file_put_contents($project . '/.data/save.iedata', 'normal save sentinel');
file_put_contents($project . '/.data/player-settings.json', 'normal settings sentinel');
file_put_contents($dataRoot . '/player-settings.json', 'isolated settings sentinel');
$canRunPosix = PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal') && function_exists('posix_kill');
$windowCalls = 0;
$forwarded = '';
$window = new TerminalSessionWindow(platform: 'Windows',
    commandRunner: static function (array $arguments, array $descriptors, array $options) use (&$windowCalls, $root) {
        $windowCalls++;
        assertLaunchContext(getenv('ICHILOTO_LAUNCH_CONTEXT') === false, 'Context leaked into the window opener.');
        return TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'sys_temp_dir=' . $root,
            __FILE__, '--headless-worker', ...array_slice($arguments, -3)],
            [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']]);
    }, findExecutable: static fn(string $name): ?string => $name);
$terminal = new TerminalSessionLauncher($window, $root,
    outputWriter: static function (string $message) use (&$forwarded): void { $forwarded .= $message; });
$command = new PlayCommand(
    rendererUpdateChecker: new SourceRendererUpdateChecker(locateEngine: static fn(string $path): string => $path),
    terminalSession: $terminal,
);
$tester = new CommandTester($command);
$run = static function (array $options) use ($tester, $project, $capture): int {
    if (is_file($capture)) { unlink($capture); }
    return $tester->execute(['--directory' => $project, '--no-tmux' => true, '--renderer' => 'terminal', ...$options], ['interactive' => false]);
};
$readCapture = static fn(): array => json_decode((string) file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);
$encode = static fn(array $context): string => json_encode($context, JSON_THROW_ON_ERROR);
$errorLog = $dataRoot . '/logs/error.log';
$builder = new GameLaunchCommandBuilder();

try {
    assertLaunchContext($command->getDefinition()->getOption('launch-context')->isValueRequired(), 'Context is not a required-value option.');
    foreach (['{}', '{"mode":"title"}', '{"mode":"new-game","start":{"mapId":"synthetic/field","x":2,"y":3}}',
        '{"mode":"new-game","start":{"mapId":"synthetic/field","x":2,"y":3},"cinematicId":"test-scene"}',
        '{"battleTest":{}}', '{"battleTest":{"troop":"Synthetic troop","arena":"Synthetic arena"}}',
        '{}' . str_repeat(' ', 65534)] as $json) {
        assertLaunchContext($run(['--launch-context' => $json]) === Command::SUCCESS, 'Valid real-Engine context refused: ' . $tester->getDisplay());
        $captured = $readCapture();
        assertLaunchContext($captured['context'] === $json && $captured['start'] === false
            && $captured['directory'] === $project && $captured['renderer'] === 'terminal', 'Context transport changed or lost intent.');
    }
    foreach (['', '[]', 'null', 'true', '"text"', '{', '{}' . str_repeat(' ', 65535),
        '{"unknown":true}', '{"mode":"continue"}', '{"mode":null}', '{"dataRoot":null}',
        '{"start":[]}', '{"battleTest":[]}', '{"battleTest":null}', '{"start":{"mapId":"map","x":0,"y":0}}',
        '{"mode":"new-game","start":{"mapId":"../map","x":0,"y":0}}',
        '{"mode":"new-game","start":{"mapId":"map","x":1.5,"y":0}}',
        '{"cinematicId":"test"}', '{"mode":"title","battleTest":{}}'] as $json) {
        assertLaunchContext($run(['--launch-context' => $json]) === Command::INVALID && !is_file($capture),
            'Invalid context launched: ' . $json);
    }
    assertLaunchContext($run(['--launch-context' => '{}', '--start' => 'title']) === Command::INVALID
        && !is_file($capture) && str_contains($tester->getDisplay(), 'cannot be combined'), 'Competing --start launched.');
    foreach (['title', ''] as $inheritedMode) {
        putenv('ICHILOTO_START_MODE=' . $inheritedMode);
        assertLaunchContext($run(['--launch-context' => '{}']) === Command::INVALID && !is_file($capture), 'Inherited startup mode launched with context.');
    }
    putenv('ICHILOTO_START_MODE');
    putenv('ICHILOTO_LAUNCH_CONTEXT={"mode":"title"}');
    assertLaunchContext($run([]) === Command::INVALID && !is_file($capture)
        && str_contains($tester->getDisplay(), '--launch-context'), 'Inherited-only launch intent silently reached an unchecked Engine.');
    assertLaunchContext(!isset($builder->buildEnvironment('terminal')['ICHILOTO_LAUNCH_CONTEXT']), 'Shared builder inherited unchecked context.');
    putenv('ICHILOTO_LAUNCH_CONTEXT');

    file_put_contents($autoload, '<?php');
    assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID
        && !is_file($capture) && !is_dir($dataRoot . '/logs')
        && str_contains($tester->getDisplay(), 'Update its Engine'), 'Old project Engine ignored isolated launch intent or wrote logs.');
    assertLaunchContext($run([]) === Command::SUCCESS && $readCapture()['context'] === false, 'Ordinary play on an old Engine regressed.');
    file_put_contents($autoload, $autoloadSource . ' echo "unexpected output";');
    // Play asks the project's Engine for its format first, and that probe refuses the noise too.
    assertLaunchContext($run(['--launch-context' => '{}']) === Command::INVALID && !is_file($capture)
        && str_contains($tester->getDisplay(), 'could not report the project format'), 'Noisy format result was trusted.');
    try {
        new ProjectStartupCheck()->prepareLaunchContext($project, '{}');
        $noisyContextRefused = false;
    } catch (RuntimeException $exception) {
        $noisyContextRefused = str_contains($exception->getMessage(), 'invalid launch-context support result');
    }
    assertLaunchContext($noisyContextRefused, 'Noisy support result was trusted.');
    file_put_contents($autoload, $autoloadSource);

    mkdir($root . '/foreign', 0700);
    file_put_contents($root . '/foreign/keep.txt', 'foreign cache sentinel');
    foreach (['', '/', 'relative-root', $root . '/missing', $project, $project . '/.data', $root,
        $dataRoot . '/', $dataRoot . "/nul\0"] as $path) {
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $path])]) === Command::INVALID
            && !is_file($capture), 'Unsafe dataRoot launched: ' . $path);
    }
    file_put_contents($dataRoot . '/save.iedata', 'not a fresh data root');
    assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Nonfresh dataRoot launched.');
    unlink($dataRoot . '/save.iedata');
    file_put_contents($dataRoot . '/logs', 'not a directory');
    assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Regular-file logs path launched.');
    unlink($dataRoot . '/logs');
    if (PHP_OS_FAMILY !== 'Windows') {
        chmod($dataRoot, 0500);
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Nonwritable dataRoot launched.');
        chmod($dataRoot, 0700);
        symlink($dataRoot, $root . '/linked-root');
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $root . '/linked-root'])]) === Command::INVALID && !is_file($capture), 'Symlink root launched.');
        symlink($root . '/foreign', $dataRoot . '/logs');
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Symlink logs launched.');
        unlink($dataRoot . '/logs');
        mkdir($dataRoot . '/logs', 0700);
        symlink($root . '/foreign/keep.txt', $errorLog);
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Symlink error log launched.');
        unlink($errorLog);
        symlink($root . '/missing-log', $errorLog);
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Dangling error-log symlink launched.');
        unlink($errorLog);
        unlink($dataRoot . '/player-settings.json');
        symlink($root . '/foreign/keep.txt', $dataRoot . '/player-settings.json');
        assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Symlink settings launched.');
        unlink($dataRoot . '/player-settings.json');
        file_put_contents($dataRoot . '/player-settings.json', 'isolated settings sentinel');
    } else {
        mkdir($dataRoot . '/logs', 0700);
    }
    mkdir($errorLog, 0700);
    assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Non-file initial error log launched.');
    rmdir($errorLog);
    mkdir($dataRoot . '/logs/nested', 0700);
    assertLaunchContext($run(['--launch-context' => $encode(['dataRoot' => $dataRoot])]) === Command::INVALID && !is_file($capture), 'Nested initial logs launched.');
    rmdir($dataRoot . '/logs/nested');
    file_put_contents($dataRoot . '/logs/playtest.log', 'editor log sentinel');
    $projectLogBefore = file_get_contents($project . '/logs/error.log');
    $json = $encode(['mode' => 'new-game', 'dataRoot' => $dataRoot]);
    assertLaunchContext($run(['--launch-context' => $json]) === Command::SUCCESS, 'Isolated context refused: ' . $tester->getDisplay());
    assertLaunchContext($readCapture()['errorLog'] === $errorLog && $readCapture()['context'] === $json, 'Direct PHP log/context lost isolation.');
    assertLaunchContext(str_contains(file_get_contents($errorLog), 'synthetic game stderr')
        && str_contains(file_get_contents($errorLog), 'synthetic PHP error_log'), 'Direct stderr and PHP log were not routed together.');
    $callsBefore = $windowCalls;
    assertLaunchContext($run(['--launch-context' => $json, '--renderer' => 'gpui', '--terminal-window' => true]) === Command::SUCCESS
        && $readCapture()['renderer'] === 'gpui' && $readCapture()['context'] === $json
        && $readCapture()['errorLog'] === $errorLog && $windowCalls === $callsBefore, 'Graphical selection changed context isolation or opened a Terminal window.');

    if ($canRunPosix) {
        file_put_contents($errorLog, '');
        assertLaunchContext($run(['--launch-context' => $json, '--terminal-window' => true]) === Command::SUCCESS,
            'Headless Terminal transport refused: ' . $tester->getDisplay() . $forwarded);
        assertLaunchContext($readCapture()['diagnostic'] === $errorLog && $readCapture()['errorLog'] === $errorLog
            && $readCapture()['context'] === $json && $readCapture()['start'] === false, 'Terminal lost isolated log/context intent.');
        assertLaunchContext(str_contains(file_get_contents($errorLog), 'synthetic game stderr')
            && str_contains(file_get_contents($errorLog), 'synthetic PHP error_log')
            && str_contains($forwarded, 'synthetic game stderr') && str_contains($forwarded, 'synthetic PHP error_log'), 'Terminal stderr/PHP diagnostics did not reach the owner.');
        assertLaunchContext(!str_contains(file_get_contents($root . '/worker.log'), 'synthetic game stderr'), 'Terminal stderr still went to the window.');
        assertLaunchContext(glob($root . '/ichiloto-terminal-*') === [], 'Terminal left owned scratch behind.');
        putenv('FIXTURE_GAME_EXIT=23');
        assertLaunchContext($run(['--launch-context' => $json, '--terminal-window' => true]) === Command::FAILURE
            && str_contains($tester->getDisplay(), 'exited unexpectedly'), 'Terminal child failure was lost.');
        putenv('FIXTURE_GAME_EXIT');
        $callsBefore = $windowCalls;
        file_put_contents($autoload, '<?php');
        assertLaunchContext($run(['--launch-context' => $json, '--terminal-window' => true]) === Command::INVALID
            && !is_file($capture) && $windowCalls === $callsBefore, 'Old Engine opened a window before refusal.');
        file_put_contents($autoload, $autoloadSource);
    }

    if (PHP_OS_FAMILY !== 'Windows') {
        mkdir($root . '/bin', 0700);
        $fakeTmux = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php
if (getenv('ICHILOTO_LAUNCH_CONTEXT') !== false) { exit(124); }
if ($argv[1] === 'has-session') { exit(is_file(dirname(__DIR__) . '/existing-session') ? 0 : 1); }
if ($argv[1] === 'new-session') {
    file_put_contents(dirname(__DIR__) . '/tmux-arguments.json', json_encode(array_slice($argv, 1), JSON_THROW_ON_ERROR));
    chdir($argv[6]);
    putenv('ICHILOTO_START_MODE=stale-tmux-server-mode');
    putenv('ICHILOTO_LAUNCH_CONTEXT=stale-tmux-server-context');
    passthru($argv[7], $code);
    exit($code);
}
exit(0);
PHP;
        file_put_contents($root . '/bin/tmux', $fakeTmux);
        chmod($root . '/bin/tmux', 0755);
        putenv('PATH=' . $root . '/bin:' . $savedEnvironment['PATH']);
        if (is_file($capture)) { unlink($capture); }
        $tmuxResult = runLaunchContextTmuxCli($project, $json);
        assertLaunchContext($tmuxResult['code'] === Command::SUCCESS, 'Synthetic tmux CLI launch failed: ' . $tmuxResult['output']);
        assertLaunchContext($readCapture()['context'] === $json && $readCapture()['start'] === false
            && $readCapture()['errorLog'] === $errorLog && $readCapture()['directory'] === $project, 'tmux lost context, quoting or stale-server isolation.');
        $tmuxArguments = json_decode(file_get_contents($root . '/tmux-arguments.json'), true, flags: JSON_THROW_ON_ERROR);
        assertLaunchContext($tmuxArguments[5] === $project, 'tmux project quoting changed.');
        touch($root . '/existing-session');
        unlink($capture);
        $tmuxResult = runLaunchContextTmuxCli($project, $json);
        assertLaunchContext($tmuxResult['code'] === Command::INVALID
            && !is_file($capture) && str_contains($tmuxResult['output'], 'existing tmux game'), 'Context reattached to a different running game.');
        unlink($root . '/existing-session');
        unlink($root . '/tmux-arguments.json');
        file_put_contents($autoload, '<?php');
        $tmuxResult = runLaunchContextTmuxCli($project, $json);
        assertLaunchContext($tmuxResult['code'] === Command::INVALID && !is_file($capture)
            && !is_file($root . '/tmux-arguments.json') && str_contains($tmuxResult['output'], 'Update its Engine'), 'Old Engine reached a tmux launch.');
        file_put_contents($autoload, $autoloadSource);
        putenv('PATH=' . $savedEnvironment['PATH']);
    }

    new RendererPreparationProcess()->run([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'sys_temp_dir=' . $root,
        dirname(__DIR__) . '/bin/ichiloto', 'play', '--directory=' . $project, '--no-tmux', '--no-interaction',
        '--renderer=terminal', '--launch-context=' . $json], $root);
    assertLaunchContext($readCapture()['context'] === $json && $readCapture()['errorLog'] === $errorLog, 'Registered CLI changed the final transport contract.');
    assertLaunchContext(file_get_contents($project . '/ichiloto.json') === $config && file_get_contents($main) === $mainSource
        && file_get_contents($project . '/.data/save.iedata') === 'normal save sentinel'
        && file_get_contents($project . '/.data/player-settings.json') === 'normal settings sentinel'
        && file_get_contents($dataRoot . '/player-settings.json') === 'isolated settings sentinel'
        && file_get_contents($dataRoot . '/logs/playtest.log') === 'editor log sentinel'
        && file_get_contents($project . '/logs/error.log') === $projectLogBefore
        && file_get_contents($root . '/foreign/keep.txt') === 'foreign cache sentinel', 'Launch rewrote source, settings, saves, project logs or foreign cache.');
    assertLaunchContext(getenv('ICHILOTO_LAUNCH_CONTEXT') === false && getenv('ICHILOTO_START_MODE') === false
        && getenv('ICHILOTO_RENDERER') === 'parent-renderer', 'Child launch mutated the parent environment.');
    if (!$canRunPosix) { echo "Headless POSIX Terminal worker fixtures skipped on this host.\n"; }
    echo "Play launch-context tests passed ($assertions assertions).\n";
} finally {
    foreach ($savedEnvironment as $name => $value) {
        putenv($value === false ? $name : $name . '=' . $value);
    }
    removeLaunchContextFixture($root);
}
