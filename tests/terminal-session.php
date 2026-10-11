<?php

declare(strict_types=1);

use Ichiloto\Console\Support\TerminalSessionChannel;
use Ichiloto\Console\Support\TerminalSessionLauncher;
use Ichiloto\Console\Support\TerminalSessionOwner;
use Ichiloto\Console\Support\TerminalSessionProcess;
use Ichiloto\Console\Support\TerminalSessionScratch;
use Ichiloto\Console\Support\TerminalSessionWindow;
use Ichiloto\Console\Support\TerminalSessionWorker;

ini_set('memory_limit', '512M');
require dirname(__DIR__) . '/vendor/autoload.php';

// Every synthetic process is silent and headless. No native window runner is used.
if (in_array($argv[1] ?? '', ['--worker', '--worker-delay-exit'], true)) {
    $code = TerminalSessionWorker::run(array_slice($argv, 2), new TerminalSessionWindow(platform: 'Linux'));
    if ($argv[1] === '--worker-delay-exit' && $code !== 0) {
        usleep(5000000);
    }
    exit($code);
}
if (($argv[1] ?? '') === '--game') {
    $mode = $argv[2];
    if ($mode === 'capture') {
        file_put_contents($argv[3], json_encode(['arguments' => array_slice($argv, 4), 'directory' => getcwd(), 'environment' => getenv('FIXTURE_VALUE')], JSON_THROW_ON_ERROR));
        echo "synthetic terminal stdout\n";
        fwrite(STDERR, "synthetic terminal stderr\n");
        exit(0);
    }
    if ($mode === 'diagnostic') {
        file_put_contents(getenv(TerminalSessionLauncher::DIAGNOSTIC_LOG_ENVIRONMENT), "synthetic crash diagnostic\n", FILE_APPEND);
        exit(23);
    }
    file_put_contents($argv[3], (string) getmypid());
    if ($mode === 'worker-interrupt') {
        posix_kill(posix_getppid(), SIGINT);
    }
    if ($mode === 'stubborn') {
        pcntl_async_signals(true);
        pcntl_signal(SIGTERM, SIG_IGN);
    }
    if ($mode === 'descendants') {
        $process = TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--game', 'stubborn', $argv[4]], [0 => STDIN, 1 => STDOUT, 2 => STDERR]);
        while (true) {
            usleep(20000);
        }
    }
    if ($mode === 'residual') {
        $process = TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--game', 'stubborn', $argv[4]], [0 => STDIN, 1 => STDOUT, 2 => STDERR]);
        usleep(500000);
        exit(0);
    }
    if ($mode === 'brief') {
        usleep(3500000);
        exit(0);
    }
    while (true) {
        usleep(20000);
    }
}

/** @var list<TerminalSessionProcess> $processes */
$processes = [];
function assertTerminal(bool $condition, string $message): void
{
    if (! $condition) {
        throw new RuntimeException($message);
    }
}

function waitForTerminal(callable $condition, string $message, float $timeout = 5.0): void
{
    $deadline = microtime(true) + $timeout;
    while (! $condition()) {
        if (microtime(true) >= $deadline) {
            throw new RuntimeException($message);
        }
        usleep(20000);
    }
}

/** @return list<string> */
function buildFixtureGame(string $mode, string ...$arguments): array
{
    return [PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--game', $mode, ...$arguments];
}

/** Returns an injected Windows-shaped opener but runs only a headless PHP worker. */
function createHeadlessWindow(string $root, ?callable $beforeStart = null, float $delay = 0.0, bool $delayExit = false): TerminalSessionWindow
{
    return new TerminalSessionWindow(platform: 'Windows', commandRunner: static function (array $arguments, array $descriptors, array $options) use ($root, $beforeStart, $delay, $delayExit) {
        assertTerminal(($options['create_new_console'] ?? false) === true, 'Windows requests its own console');
        assertTerminal($descriptors === [], 'Windows does not inherit GUI log handles');
        $beforeStart?->__invoke($arguments);
        $worker = [PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, $delayExit ? '--worker-delay-exit' : '--worker', ...array_slice($arguments, -3)];
        if ($delay > 0) {
            $worker = [PHP_BINARY, '-d', 'memory_limit=512M', '-r', 'usleep((int) $argv[1]); $p = proc_open(array_slice($argv, 2), [STDIN, STDOUT, STDERR], $pipes); exit(proc_close($p));', (string) (int) ($delay * 1000000), ...$worker];
        }
        $process = TerminalSessionProcess::start($worker, [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']]);
        if ($delayExit && is_resource($process)) {
            file_put_contents($root . '/display.pid', (string) proc_get_status($process)['pid']);
        }

        return $process;
    }, findExecutable: static fn (string $name): ?string => $name);
}

if (($argv[1] ?? '') === '--owner') {
    $root = $argv[2];
    $mode = $argv[3];
    $launcher = new TerminalSessionLauncher(createHeadlessWindow($root), scratchRoot: $root, readyTimeout: 3.0);
    exit($launcher->launch($root, buildFixtureGame($mode, $root . '/game.pid', $root . '/descendant.pid'), []) ?? 125);
}
if (($argv[1] ?? '') === '--editor') {
    $root = $argv[2];
    $process = TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--owner', $root, 'stubborn'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/owner.log', 'a'], 2 => ['file', $root . '/owner.log', 'a']]);
    file_put_contents($root . '/play.pid', (string) proc_get_status($process)['pid']);
    while (true) {
        usleep(20000);
    }
}

$base = sys_get_temp_dir();
if (! is_dir($base)) {
    throw new RuntimeException('The configured test scratch root is unavailable.');
}
$root = $base . '/terminal-session-' . bin2hex(random_bytes(12));
mkdir($root, 0700);
$project = $root . (PHP_OS_FAMILY === 'Windows' ? "/project space ' dollar $;" : "/project space ' quote \" dollar $;");
mkdir($project, 0700);
$output = '';
$writer = static function (string $text) use (&$output): void { $output .= $text; };
$originalDisplay = getenv('DISPLAY');

try {
    $nonce = str_repeat('a', 64);
    $workerArguments = ["/php path/'quoted\"", '-d', 'memory_limit=512M', "/worker path/'\";$()\nfile.php", '127.0.0.1:1234', $nonce, '123.456'];
    $commands = [];
    $runner = static function (array $arguments, array $descriptors, array $options) use (&$commands) {
        $commands[] = [$arguments, $descriptors, $options];
        return false;
    };
    $finder = static fn (string $name): ?string => '/available/' . basename($name);
    $mac = new TerminalSessionWindow(platform: 'Darwin', commandRunner: $runner, findExecutable: $finder);
    $script = $mac->buildOpenCommand($workerArguments, $nonce)['arguments'][2];
    assertTerminal(str_contains($script, 'set ownedTab to do script ') && ! str_contains($script, ' in window'), 'macOS creates a dedicated new window');
    assertTerminal(str_contains($script, "'\\\\''") && str_contains($script, '\\"') && str_contains($script, '\\n'), 'macOS uses shell and AppleScript quoting independently');
    $mac->closeOwnedWindow($nonce);
    $closeScript = $commands[0][0][2];
    assertTerminal(str_contains($closeScript, 'count of tabs of ownedWindow') && str_contains($closeScript, $nonce) && str_contains($closeScript, 'busy of tab 1'), 'macOS closes only an idle, nonce-owned, single-tab window');
    assertTerminal(! str_contains($closeScript, 'quit') && ! str_contains($closeScript, 'close every'), 'macOS never quits the shared Terminal app');
    putenv('DISPLAY=:headless-fixture');
    $linux = new TerminalSessionWindow(platform: 'Linux', commandRunner: $runner, findExecutable: $finder);
    $linuxCommand = $linux->buildOpenCommand($workerArguments, $nonce)['arguments'];
    assertTerminal(array_slice($linuxCommand, 4) === $workerArguments && $linuxCommand[3] === '-e', 'xterm receives a literal executable vector');
    $canRunLifecycle = PHP_OS_FAMILY !== 'Windows' && function_exists('pcntl_signal') && function_exists('posix_kill');
    assertTerminal($canRunLifecycle ? $linux->describeUnavailable() === null : str_contains($linux->describeUnavailable() ?? '', 'extensions'), 'Linux adapter checks its runtime requirements');
    putenv('DISPLAY');
    assertTerminal(str_contains($linux->describeUnavailable() ?? '', $canRunLifecycle ? 'display' : 'extensions'), 'Linux without prerequisites refuses explicitly');
    $missing = new TerminalSessionWindow(platform: 'Linux', commandRunner: $runner, findExecutable: static fn (): ?string => null);
    assertTerminal(str_contains($missing->describeUnavailable() ?? '', $canRunLifecycle ? 'xterm' : 'extensions'), 'Linux without a supported terminal diagnoses availability');
    assertTerminal(str_contains((new TerminalSessionWindow(platform: 'Other'))->describeUnavailable() ?? '', 'unsupported'), 'unsupported platform refuses');
    $windows = new TerminalSessionWindow(platform: 'Windows', commandRunner: $runner, findExecutable: $finder);
    $winCommand = $windows->buildOpenCommand($workerArguments, $nonce);
    assertTerminal($winCommand['arguments'] === $workerArguments && $winCommand['options']['create_new_console'] === true, 'Windows bypasses shells and owns its console');
    $windowsParent = ['ProcessId' => 123, 'CreationDate' => 'original-parent'];
    $windowsOwner = new TerminalSessionOwner(platform: 'Windows', queryWindows: static function () use (&$windowsParent): ?array { return $windowsParent; });
    assertTerminal($windowsOwner->isAlive(), 'Windows owner starts with a stable process identity');
    $windowsParent['CreationDate'] = 'reused-pid';
    assertTerminal(! $windowsOwner->isAlive(), 'Windows does not mistake a reused PID for the editor');
    $windowsParent = null;
    assertTerminal(! $windowsOwner->isAlive(), 'Windows parent disappearance cancels');

    if (! $canRunLifecycle) {
        echo "terminal-session: structural checks ok; POSIX lifecycle fixtures skipped on this host. No native window opened.\n";

        return;
    }

    $launcher = new TerminalSessionLauncher(createHeadlessWindow($root), $root, outputWriter: $writer);
    $capture = $root . '/capture.json';
    $values = ['space value', "single'quote", 'double"quote', '$(touch NOT_ALLOWED)', '; exit 77', "line\nbreak", 'percent%PATH%', 'back\\slash', ''];
    $code = $launcher->launch($project, buildFixtureGame('capture', $capture, ...$values), ['FIXTURE_VALUE' => "literal ' \" ; $()"]);
    assertTerminal($code === 0, 'normal completion returns the child exit code');
    $captured = json_decode(file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR);
    assertTerminal($captured['arguments'] === $values && $captured['directory'] === $project, 'argument/path injection characters arrive literally');
    assertTerminal($captured['environment'] === "literal ' \" ; $()", 'environment data is not shell evaluated');
    assertTerminal(str_contains(file_get_contents($root . '/worker.log'), 'synthetic terminal stdout') && str_contains(file_get_contents($root . '/worker.log'), 'synthetic terminal stderr'), 'game inherits the worker terminal streams');
    assertTerminal(glob($root . '/ichiloto-terminal-*') === [], 'completion removes only owned scratch');
    $macLiteralScript = $mac->buildOpenCommand(buildFixtureGame('capture', $capture, ...$values), $nonce)['arguments'][2];
    preg_match('/set ownedTab to do script (".*")/', $macLiteralScript, $macLiteral);
    $shellCommand = json_decode($macLiteral[1], true, flags: JSON_THROW_ON_ERROR);
    $shellProcess = TerminalSessionProcess::start(['/bin/sh', '-c', $shellCommand], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/worker.log', 'a'], 2 => ['file', $root . '/worker.log', 'a']], $project);
    assertTerminal(is_resource($shellProcess) && proc_close($shellProcess) === 0, 'macOS generated shell command runs headlessly');
    assertTerminal(json_decode(file_get_contents($capture), true, flags: JSON_THROW_ON_ERROR)['arguments'] === $values, 'actual macOS shell quoting preserves every literal argument');

    $rogue = new TerminalSessionLauncher(createHeadlessWindow($root, beforeStart: static function (array $arguments): void {
        $stream = stream_socket_client('tcp://' . $arguments[count($arguments) - 3]);
        fwrite($stream, json_encode(['type' => 'hello', 'nonce' => str_repeat('c', 64)]) . "\n");
        fclose($stream);
    }), $root, outputWriter: $writer);
    assertTerminal($rogue->launch($project, buildFixtureGame('capture', $capture), []) === 0, 'a foreign nonce is rejected without authorizing or displacing the real worker');
    assertTerminal($launcher->launch($project, ['/not/an/executable'], []) === null && str_contains($launcher->getFailureReason() ?? '', 'executable'), 'worker start refusal reaches Play with its diagnostic');

    $diagnostic = $root . '/diagnostic.log';
    file_put_contents($diagnostic, 'historical log is not replayed' . "\n");
    $code = $launcher->launch($project, buildFixtureGame('diagnostic'), [TerminalSessionLauncher::DIAGNOSTIC_LOG_ENVIRONMENT => $diagnostic]);
    assertTerminal($code === 23, 'nonzero child status reaches Play without waiting on a crash display');
    assertTerminal(str_contains($output, 'synthetic crash diagnostic') && ! str_contains($output, 'historical log'), 'new diagnostic log data reaches the parent output');
    assertTerminal(str_contains($output, 'status 23'), 'failure summary reaches the editor log');
    // Owned mode removes crash-window holding, while preserving the game exit/log.
    $display = new TerminalSessionLauncher(createHeadlessWindow($root, delayExit: true), $root, outputWriter: $writer);
    $started = microtime(true);
    assertTerminal($display->launch($project, buildFixtureGame('diagnostic'), [TerminalSessionLauncher::DIAGNOSTIC_LOG_ENVIRONMENT => $diagnostic]) === 23 && microtime(true) - $started < 3.5, 'nonzero completion stops a lingering window process within its bounded cleanup');
    assertTerminal(glob($root . '/ichiloto-terminal-*') === [], 'nonzero completion holds no owned scratch');
    $displayPid = (int) file_get_contents($root . '/display.pid');
    assertTerminal(! @posix_kill($displayPid, 0), 'owned nonzero completion leaves no crash display process');

    $failure = new TerminalSessionLauncher($windows, $root, 0.2, $writer);
    assertTerminal($failure->launch($project, buildFixtureGame('capture', $capture), []) === null && str_contains($failure->getFailureReason() ?? '', 'open'), 'window opening failure refuses, without a GUI-log fallback');
    assertTerminal($failure->launch('/missing/directory', [], []) === null, 'invalid source directory refuses before starting');
    assertTerminal($failure->launch($project, ['bad' . "\0" . 'argument'], []) === null, 'NUL arguments refuse');
    assertTerminal($failure->launch($project, buildFixtureGame('capture', $capture), ['BAD=KEY' => 'value']) === null, 'invalid environment refuses');

    $neverReady = new TerminalSessionWindow(platform: 'Windows', findExecutable: $finder, commandRunner: static fn () => TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', '-r', 'usleep(1000000);'], []));
    $timeout = new TerminalSessionLauncher($neverReady, $root, 0.12, $writer);
    $started = microtime(true);
    assertTerminal($timeout->launch($project, buildFixtureGame('capture', $capture), []) === null, 'ready timeout refuses a window that never rendezvouses');
    assertTerminal(microtime(true) - $started < 3.5 && str_contains($timeout->getFailureReason() ?? '', 'timeout'), 'pre-ready waiting is bounded');

    $lateCapture = $root . '/late.json';
    $late = new TerminalSessionLauncher(createHeadlessWindow($root, delay: 0.35), $root, 0.08, $writer);
    assertTerminal($late->launch($project, buildFixtureGame('capture', $lateCapture), []) === null, 'late startup expires');
    usleep(450000);
    assertTerminal(! file_exists($lateCapture) && glob($root . '/ichiloto-terminal-*') === [], 'late worker never starts a game or leaves scratch');

    $pidFile = $root . '/live.pid';
    $started = microtime(true);
    $live = new TerminalSessionLauncher(createHeadlessWindow($root), $root, 3.0, $writer);
    assertTerminal($live->launch($project, buildFixtureGame('brief', $pidFile), []) === 0 && microtime(true) - $started >= 3.5, 'ready timeout does not limit a live game and Play remains synchronous');
    unlink($pidFile);
    $cancel = new TerminalSessionLauncher(createHeadlessWindow($root), $root, outputWriter: $writer, cancellationRequested: static fn (): bool => is_file($pidFile));
    assertTerminal($cancel->launch($project, buildFixtureGame('stubborn', $pidFile), []) === 143, 'explicit cancellation returns a meaningful status');
    $gamePid = (int) file_get_contents($pidFile);
    waitForTerminal(static fn (): bool => ! @posix_kill($gamePid, 0), 'cancellation left the stubborn game alive');
    assertTerminal(glob($root . '/ichiloto-terminal-*') === [], 'cancellation cleans authenticated scratch');
    assertTerminal($launcher->launch($project, buildFixtureGame('worker-interrupt', $pidFile), []) === 130, 'interrupting the terminal worker reports interruption rather than an invented crash status');
    $residual = $root . '/residual.pid';
    assertTerminal($launcher->launch($project, buildFixtureGame('residual', $root . '/live.pid', $residual), []) === 0, 'normal completion also reaps residual consumers');
    $residualPid = (int) file_get_contents($residual);
    waitForTerminal(static fn (): bool => ! @posix_kill($residualPid, 0), 'normal completion left an overlay consumer alive');

    // A foreign or substituted scratch directory is never recursively removed.
    $scratch = new TerminalSessionScratch($root, $nonce);
    $scratch->create();
    file_put_contents($scratch->path . '/foreign', 'preserve');
    $scratch->remove();
    assertTerminal(is_file($scratch->path . '/foreign') && is_file($scratch->path . '/owner'), 'foreign files prevent cleanup ownership loss');
    unlink($scratch->path . '/foreign');
    $scratch->remove();
    assertTerminal(! file_exists($scratch->path), 'owned-only scratch cleans');
    mkdir($scratch->path, 0700);
    file_put_contents($scratch->path . '/owner', str_repeat('b', 64));
    $scratch->remove();
    assertTerminal(is_file($scratch->path . '/owner'), 'wrong owner is preserved');
    unlink($scratch->path . '/owner');
    rmdir($scratch->path);
    symlink($project, $scratch->path);
    $scratch->remove();
    assertTerminal(is_link($scratch->path) && is_dir($project), 'scratch cleanup never follows a substituted directory symlink');
    unlink($scratch->path);

    foreach ([SIGINT, SIGTERM, SIGKILL] as $signal) {
        @unlink($root . '/game.pid');
        @unlink($root . '/descendant.pid');
        $resource = TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--owner', $root, 'descendants'], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/owner.log', 'a'], 2 => ['file', $root . '/owner.log', 'a']]);
        assertTerminal(is_resource($resource), 'synthetic owner starts');
        $owner = new TerminalSessionProcess($resource);
        $processes[] = $owner;
        waitForTerminal(static fn (): bool => is_file($root . '/descendant.pid'), 'game descendant did not become ready');
        // Allow the worker to record its game-local descendants before forced death.
        usleep(300000);
        $gamePid = (int) file_get_contents($root . '/game.pid');
        $descendantPid = (int) file_get_contents($root . '/descendant.pid');
        posix_kill($owner->getProcessId(), $signal);
        waitForTerminal(static fn (): bool => ! $owner->isRunning(), 'Play did not exit after the signal');
        $owner->close();
        waitForTerminal(static fn (): bool => ! @posix_kill($gamePid, 0), 'parent death left the owned game alive');
        waitForTerminal(static fn (): bool => ! @posix_kill($descendantPid, 0), 'parent death left an owned descendant alive');
        waitForTerminal(static fn (): bool => glob($root . '/ichiloto-terminal-*') === [], 'parent death left owned scratch');
    }

    @unlink($root . '/game.pid');
    $resource = TerminalSessionProcess::start([PHP_BINARY, '-d', 'memory_limit=512M', __FILE__, '--editor', $root], [0 => ['file', '/dev/null', 'r'], 1 => ['file', $root . '/owner.log', 'a'], 2 => ['file', $root . '/owner.log', 'a']]);
    assertTerminal(is_resource($resource), 'synthetic editor starts');
    $editor = new TerminalSessionProcess($resource);
    $processes[] = $editor;
    waitForTerminal(static fn (): bool => is_file($root . '/game.pid'), 'editor-owned game did not start');
    $gamePid = (int) file_get_contents($root . '/game.pid');
    $playPid = (int) file_get_contents($root . '/play.pid');
    posix_kill($editor->getProcessId(), SIGKILL);
    waitForTerminal(static fn (): bool => ! $editor->isRunning(), 'editor did not die');
    $editor->close();
    waitForTerminal(static fn (): bool => ! @posix_kill($gamePid, 0), 'editor death left the terminal game alive while Play was reparented');
    waitForTerminal(static fn (): bool => ! @posix_kill($playPid, 0), 'editor death left Play alive');
    assertTerminal(glob($root . '/ichiloto-terminal-*') === [], 'editor death cleans owned scratch');

    echo "terminal-session: ok; headless lifecycle and command construction only; no native window opened.\n";
} finally {
    foreach ($processes as $process) {
        $process->stop();
    }
    $originalDisplay === false ? putenv('DISPLAY') : putenv('DISPLAY=' . $originalDisplay);
    foreach (glob($root . '/ichiloto-terminal-*') ?: [] as $path) {
        $token = substr(basename($path), strlen('ichiloto-terminal-'));
        new TerminalSessionScratch($root, $token)->remove();
    }
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && !$file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
