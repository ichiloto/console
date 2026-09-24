<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\PlayCommand;
use Ichiloto\Console\Commands\RendererUpdateCommand;
use Ichiloto\Console\Support\RendererPackageInstaller;
use Ichiloto\Console\Support\RendererPreparationProcess;
use Ichiloto\Console\Support\SourceRendererUpdateChecker;
use Ichiloto\Console\Support\SourceRendererUpdater;
use Ichiloto\Console\Support\TerminalInteractivity;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertUpdate(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function expectUpdateFailure(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $error) {
        assertUpdate(str_contains($error->getMessage(), $message), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected update failure: ' . $message);
}

$root = sys_get_temp_dir() . '/ichiloto-renderer-update-' . bin2hex(random_bytes(8));
$engine = $root . '/engine';
$source = $root . '/native';
$project = $root . '/project with spaces';
$installed = $engine . '/resources/renderers/installed';
$platform = RendererPackageInstaller::hostPlatform();
$output = new BufferedOutput();
$originalPath = getenv('PATH');
$buildCount = static fn (): int => is_file($source . '/builds') ? count(file($source . '/builds')) : 0;
try {
    foreach ([$engine . '/resources/renderers', $engine . '/src/Core', $engine . '/.git',
        $source . '/.git', $project . '/vendor', $project . '/assets'] as $directory) { mkdir($directory, 0755, true); }
    copy(__DIR__ . '/fixtures/renderer-builder.php', $source . '/builder.php');
    file_put_contents($source . '/platform', $platform);
    file_put_contents($source . '/input', 'revision-one');
    $declarationFile = $engine . '/resources/renderers/development.json';
    file_put_contents($declarationFile, json_encode(['version' => 1,
        'sources' => ['gpui' => ['directory' => '../../../native', 'builder' => 'builder.php']]], JSON_THROW_ON_ERROR));
    file_put_contents($engine . '/src/Core/Game.php', '<?php namespace Ichiloto\\Engine\\Core; class Game {}');
    file_put_contents($project . '/vendor/autoload.php', '<?php require ' . var_export($engine . '/src/Core/Game.php', true) . ';');
    file_put_contents($project . '/ichiloto.json', json_encode(['main' => 'main.php']));
    file_put_contents($project . '/main.php', '<?php file_put_contents(__DIR__ . "/launched", getenv("ICHILOTO_RENDERER") ?: "none");');

    $checker = new SourceRendererUpdateChecker();
    $updater = new SourceRendererUpdater($checker);
    assertUpdate($checker->check('/does/not/exist', 'terminal') === null && ! is_dir($installed),
        'Terminal check must be a no-op.');
    $first = $checker->check($project, 'gpui');
    assertUpdate($first !== null && ! $first->current && ! $first->skipped && ! is_dir($installed)
        && $buildCount() === 0, 'Checking must not build or write.');

    $choice = 'continue';
    $prompts = 0;
    $play = new PlayCommand(rendererUpdateChecker: $checker, rendererUpdater: $updater,
        terminalInteractivity: new TerminalInteractivity(static fn (): bool => true, static fn (): bool => true),
        rendererUpdatePrompt: static function (string $label, array $options) use (&$choice, &$prompts): string {
            $prompts++;
            assertUpdate(array_keys($options) === ['update', 'continue', 'skip'], 'Unexpected update choices.');
            return $choice;
        });
    $playTester = new CommandTester($play);
    $launch = static function (bool $interactive = true) use ($playTester, $project): array {
        @unlink($project . '/launched');
        $status = $playTester->execute(['--directory' => $project, '--renderer' => 'gpui', '--no-tmux' => true],
            ['interactive' => $interactive]);
        return [$status, $playTester->getDisplay(), is_file($project . '/launched') ? file_get_contents($project . '/launched') : null];
    };
    [$status, $display, $renderer] = $launch(false);
    assertUpdate($status === Command::SUCCESS && $renderer === 'gpui' && $prompts === 0
        && $buildCount() === 0 && ! is_dir($installed) && str_contains($display, 'renderer:update'),
        'Noninteractive play must report and launch without prompt or build.');
    $launch();
    assertUpdate($prompts === 1 && $buildCount() === 0, 'Continue must not build or save a skip.');
    $choice = 'skip';
    $launch();
    assertUpdate($prompts === 2 && is_file($first->getSkipFile()) && $buildCount() === 0,
        'Skip must record offered fingerprint without building.');
    $launch();
    assertUpdate($prompts === 2, 'Skip must suppress repeat prompts for that fingerprint.');
    file_put_contents($source . '/input', 'revision-two');
    $launch();
    assertUpdate($prompts === 3, 'New fingerprint must be offered after skip.');
    $headless = new CommandTester(new PlayCommand(rendererUpdateChecker: $checker, rendererUpdater: $updater,
        terminalInteractivity: new TerminalInteractivity(static fn (): bool => false, static fn (): bool => false),
        rendererUpdatePrompt: static fn (): never => throw new RuntimeException('No TTY must not prompt.')));
    $status = $headless->execute(['--directory' => $project, '--renderer' => 'gpui', '--no-tmux' => true],
        ['interactive' => true]);
    assertUpdate($status === Command::SUCCESS && $buildCount() === 0,
        'Interactive input without a terminal must not prompt or build.');

    $commandTester = new CommandTester(new RendererUpdateCommand($updater));
    $status = $commandTester->execute(['--directory' => $project], ['interactive' => false]);
    $receipt = $first->getReceiptFile();
    $executable = $installed . '/gpui/' . $platform . '/renderer';
    assertUpdate($status === Command::SUCCESS && $buildCount() === 1 && is_executable($executable)
        && is_file($receipt) && ! is_file($first->getSkipFile()),
        'Real renderer:update command must install and clear skip.');
    assertUpdate($checker->check($project, 'gpui')?->current === true, 'Verified install must be current.');
    $commandTester->execute(['--directory' => $project], ['interactive' => false]);
    assertUpdate($buildCount() === 1, 'Unchanged explicit update must reuse verified install.');
    file_put_contents($project . '/assets/portrait.png', 'changed art');
    file_put_contents($project . '/changed.php', '<?php // changed game');
    assertUpdate($checker->check($project, 'gpui')?->current === true,
        'Game assets and PHP must not invalidate renderer source.');

    file_put_contents($source . '/input', 'revision-three');
    $choice = 'update';
    [$status, , $renderer] = $launch();
    assertUpdate($status === Command::SUCCESS && $renderer === 'gpui' && $buildCount() === 2
        && count(glob($installed . '/.backups/*')) === 1, 'Update-now must install and launch.');
    file_put_contents($executable, 'tampered');
    assertUpdate($checker->check($project, 'gpui')?->current === false, 'Receipt cannot mask tampered payload.');
    $commandTester->execute(['--directory' => $project], ['interactive' => false]);
    assertUpdate($buildCount() === 3 && file_get_contents($executable) !== 'tampered',
        'Explicit update must repair tampered payload.');

    foreach (['fail' => 'Deliberate build failure', 'changed' => 'source changed during',
        'wrong-renderer' => 'does not match', 'wrong-platform' => 'does not match', 'bad-hash' => 'SHA-256'] as $mode => $message) {
        file_put_contents($source . '/input', 'failure-' . $mode);
        file_put_contents($source . '/mode', $mode);
        $before = [file_get_contents($executable), file_get_contents($installed . '/manifest.json'), file_get_contents($receipt)];
        expectUpdateFailure(fn () => $updater->update($project, 'gpui', $output), $message);
        assertUpdate($before === [file_get_contents($executable), file_get_contents($installed . '/manifest.json'), file_get_contents($receipt)],
            'Failed update changed previous install: ' . $mode);
        [$status, $display, $renderer] = $launch();
        assertUpdate($status === Command::SUCCESS && $renderer === 'gpui' && str_contains($display, 'Continuing game launch'),
            'Failed update must not stop actual launch: ' . $mode);
        if ($mode === 'fail') {
            assertUpdate($commandTester->execute(['--directory' => $project], ['interactive' => false]) === Command::FAILURE,
                'Explicit renderer:update must report build failure.');
        }
    }
    unlink($source . '/mode');
    file_put_contents($source . '/input', 'timeout-revision');
    $boundedChecker = new SourceRendererUpdateChecker(probeTimeoutSeconds: 0.3);
    file_put_contents($source . '/mode', 'hung-description');
    $started = microtime(true);
    expectUpdateFailure(fn () => $boundedChecker->check($project, 'gpui'), 'timed out');
    assertUpdate(microtime(true) - $started < 3, 'Read-only update check must be bounded.');
    $timeoutPlay = new CommandTester(new PlayCommand(rendererUpdateChecker: $boundedChecker,
        terminalInteractivity: new TerminalInteractivity(static fn (): bool => false, static fn (): bool => false)));
    $status = $timeoutPlay->execute(['--directory' => $project, '--renderer' => 'gpui', '--no-tmux' => true],
        ['interactive' => false]);
    assertUpdate($status === Command::SUCCESS && str_contains($timeoutPlay->getDisplay(), 'Continuing game launch'),
        'Timed-out check must not block real launch.');
    unlink($source . '/mode');
    $bounded = new SourceRendererUpdater($checker, buildTimeoutSeconds: 0.3, lockTimeoutSeconds: 0.3);
    file_put_contents($source . '/mode', 'hung-build');
    $started = microtime(true);
    expectUpdateFailure(fn () => $bounded->update($project, 'gpui', $output), 'timed out');
    assertUpdate(microtime(true) - $started < 3, 'Hung build must terminate promptly.');
    unlink($source . '/mode');
    $busy = fopen($installed . '/.source-update.lock', 'c');
    flock($busy, LOCK_EX);
    try {
        $started = microtime(true);
        expectUpdateFailure(fn () => $bounded->update($project, 'gpui', $output), 'lock timed out');
        assertUpdate(microtime(true) - $started < 3, 'Busy lock must be bounded.');
    } finally { flock($busy, LOCK_UN); fclose($busy); }
    $runner = new RendererPreparationProcess();
    expectUpdateFailure(fn () => $runner->run([PHP_BINARY, '-r', 'echo str_repeat("x", 70000);'], $project), '64 KiB');
    $pidFile = $root . '/hung.pid';
    $started = microtime(true);
    expectUpdateFailure(fn () => $runner->run([PHP_BINARY, '-r',
        'file_put_contents($argv[1], (string)getmypid()); fclose(STDOUT); fclose(STDERR); sleep(10);', $pidFile],
        $project, timeoutSeconds: 0.3), 'timed out');
    assertUpdate(microtime(true) - $started < 3, 'Closed tool pipes must not allow unbounded exit wait.');
    if (function_exists('posix_kill')) {
        assertUpdate(! @posix_kill((int) file_get_contents($pidFile), 0), 'Timed-out tool must terminate.');
    }

    file_put_contents($source . '/input', 'concurrent-revision');
    file_put_contents($source . '/mode', 'slow');
    $beforeBuilds = $buildCount();
    $children = [];
    for ($index = 0; $index < 2; $index++) {
        $pipes = [];
        $child = proc_open([PHP_BINARY, __DIR__ . '/fixtures/renderer-preparation-worker.php', $project],
            [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
        assertUpdate(is_resource($child), 'Could not start concurrent update fixture.');
        fclose($pipes[0]);
        $children[] = [$child, $pipes];
    }
    foreach ($children as [$child, $pipes]) {
        stream_get_contents($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]);
        assertUpdate(proc_close($child) === 0, 'Concurrent update failed: ' . $errors);
    }
    assertUpdate($buildCount() === $beforeBuilds + 1, 'Concurrent updates must recheck under lock.');
    unlink($source . '/mode');

    file_put_contents($source . '/input', 'new-after-concurrency');
    file_put_contents($first->getSkipFile(), '{invalid JSON');
    $beforeState = file_get_contents($first->getSkipFile());
    $status = $checker->check($project, 'gpui');
    assertUpdate($status !== null && ! $status->current && ! $status->skipped
        && file_get_contents($first->getSkipFile()) === $beforeState,
        'Malformed skip state must not hide an update or be rewritten by a check.');
    file_put_contents($receipt, '{invalid JSON');
    assertUpdate($checker->check($project, 'gpui')?->current === false
        && file_get_contents($receipt) === '{invalid JSON',
        'Malformed receipt must be treated as stale without check-time writes.');

    file_put_contents($source . '/platform', 'linux-x64');
    $linuxChecker = new SourceRendererUpdateChecker(platform: 'linux-x64');
    (new SourceRendererUpdater($linuxChecker))->update($project, 'gpui', $output);
    assertUpdate(is_file($installed . '/gpui/linux-x64/renderer'), 'Linux identity must select Linux package.');
    file_put_contents($source . '/platform', $platform);
    $beforeBuilds = $buildCount();
    rename($declarationFile, $declarationFile . '.saved');
    assertUpdate($checker->check($project, 'gpui') === null, 'No declaration means no update check.');
    rename($declarationFile . '.saved', $declarationFile);
    rmdir($engine . '/.git');
    file_put_contents($declarationFile, 'invalid copied declaration');
    assertUpdate($checker->check($project, 'gpui') === null, 'Distribution must ignore copied declaration.');
    $vendored = $project . '/vendor/ichiloto/engine';
    mkdir($vendored . '/.git', 0755, true);
    mkdir($vendored . '/resources/renderers', 0755, true);
    mkdir($vendored . '/src/Core', 0755, true);
    copy($engine . '/src/Core/Game.php', $vendored . '/src/Core/Game.php');
    copy($declarationFile, $vendored . '/resources/renderers/development.json');
    $autoload = file_get_contents($project . '/vendor/autoload.php');
    file_put_contents($project . '/vendor/autoload.php', '<?php require ' . var_export($vendored . '/src/Core/Game.php', true) . ';');
    assertUpdate($checker->check($project, 'gpui') === null,
        'Vendored Git Engine must not activate source update checks.');
    file_put_contents($project . '/vendor/autoload.php', $autoload);
    mkdir($engine . '/.git');
    file_put_contents($declarationFile, json_encode(['version' => 1,
        'sources' => ['gpui' => ['directory' => '../../../missing-source', 'builder' => 'builder.php']]]));
    [$status, $display, $renderer] = $launch(false);
    assertUpdate($status === Command::SUCCESS && $renderer === 'gpui' && str_contains($display, 'Continuing game launch'),
        'Missing source check must warn yet launch.');
    assertUpdate($commandTester->execute(['--directory' => $project], ['interactive' => false]) === Command::FAILURE,
        'Explicit update must report a missing source.');
    $terminalTester = new CommandTester($play);
    assertUpdate($terminalTester->execute(['--directory' => $project, '--renderer' => 'terminal', '--no-tmux' => true],
        ['interactive' => false]) === Command::SUCCESS
        && ! str_contains($terminalTester->getDisplay(), 'Renderer update check'),
        'Terminal play must not probe the missing graphical source.');

    mkdir($root . '/bin');
    file_put_contents($root . '/bin/tmux', "#!/bin/sh\nexit 0\n");
    chmod($root . '/bin/tmux', 0755);
    putenv('PATH=' . $root . '/bin:' . $originalPath);
    $method = new ReflectionMethod(PlayCommand::class, 'launchInTmux');
    $tmuxOutput = new BufferedOutput();
    $status = $method->invoke($play, $project, $project . '/main.php', $project . '/logs/error.log', 'gpui',
        new ArrayInput([]), $tmuxOutput);
    assertUpdate($status === 0 && $buildCount() === $beforeBuilds
        && ! str_contains($tmuxOutput->fetch(), 'Renderer update'), 'Tmux reattach must not check or build.');

    echo "renderer-preparation: read-only play checks and explicit updates passed (fake builder, no native launch).\n";
} finally {
    putenv($originalPath === false ? 'PATH' : 'PATH=' . $originalPath);
    if (is_dir($root)) {
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS), RecursiveIteratorIterator::CHILD_FIRST);
        foreach ($files as $file) { $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname()); }
        rmdir($root);
    }
}
