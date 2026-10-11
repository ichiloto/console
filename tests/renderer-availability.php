<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\RendererListCommand;
use Ichiloto\Console\Renderer\ProjectRendererAvailability;
use Ichiloto\Console\Renderer\RendererDescriptor;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Renderer\RendererSelector;
use Ichiloto\Console\Support\RendererPreparationProcess;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertAvailability(bool $condition, string $message): void
{
    if (! $condition) { throw new RuntimeException($message); }
}

function expectAvailabilityFailure(callable $action, string $message): void
{
    try { $action(); } catch (Throwable $error) {
        assertAvailability(str_contains($error->getMessage(), $message), 'Unexpected failure: ' . $error->getMessage());
        return;
    }
    throw new RuntimeException('Expected discovery failure: ' . $message);
}

function getAvailabilitySnapshot(string $directory): array
{
    $snapshot = [];
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS));
    foreach ($files as $file) {
        $snapshot[$file->getPathname()] = $file->isLink() ? readlink($file->getPathname()) : hash_file('sha256', $file->getPathname());
    }
    ksort($snapshot);
    return $snapshot;
}

function getReadOnlyAvailabilityReport(ProjectRendererAvailability $availability, string $directory, string $root): array
{
    $before = getAvailabilitySnapshot($root);
    try { return $availability->getReport($directory); } finally {
        assertAvailability(getAvailabilitySnapshot($root) === $before, 'Discovery changed project or installation files.');
    }
}

function removeAvailabilityDirectory(string $directory): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() && ! $file->isLink() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($directory);
}

function writeAvailabilityReportFixture(string $autoload, mixed $report): void
{
    file_put_contents($autoload, '<?php namespace Ichiloto\\Engine\\Rendering\\Launch; final class RendererRegistry {'
        . ' public function getReport() { return ' . var_export($report, true) . '; } }');
}

$root = sys_get_temp_dir() . '/console-renderer-availability-' . bin2hex(random_bytes(8));
$project = $root . '/project ; with spaces';
$engine = $root . '/project engine';
$installed = $engine . '/resources/renderers/installed';
$autoload = $project . '/vendor/autoload.php';
$manifest = $installed . '/manifest.json';
$availability = new ProjectRendererAvailability();
$runner = new RendererPreparationProcess();
$workingDirectory = getcwd();
$rendererEnvironment = getenv('ICHILOTO_RENDERER');
mkdir($project . '/vendor', 0700, true);
mkdir($engine . '/src/Rendering/Launch', 0700, true);
mkdir($installed, 0700, true);

try {
    // Copy the real discovery implementation into an isolated synthetic installation.
    foreach (['RendererRegistry', 'RendererDescriptor', 'RendererAvailability', 'RendererExecutableResolverInterface',
        'PackagedRendererExecutableResolver', 'RendererUnavailableException'] as $class) {
        copy(dirname(__DIR__, 2) . '/engine/src/Rendering/Launch/' . $class . '.php',
            $engine . '/src/Rendering/Launch/' . $class . '.php');
    }
    $projectAutoload = '<?php if (ini_get("memory_limit") !== "512M") { throw new RuntimeException("Discovery memory cap missing."); }'
        . ' if (getcwd() !== ' . var_export(realpath($project), true) . ') { throw new RuntimeException("Wrong project working directory."); }'
        . ' spl_autoload_register(static function (string $class): void {'
        . ' $prefix = "Ichiloto\\\\Engine\\\\"; if (!str_starts_with($class, $prefix)) { return; }'
        . ' $path = ' . var_export($engine . '/src/', true) . ' . str_replace("\\\\", "/", substr($class, strlen($prefix))) . ".php";'
        . ' if (is_file($path)) { require $path; } });';
    file_put_contents($autoload, $projectAutoload);
    file_put_contents($project . '/ichiloto.json', '{"main":"game.php","music":false,"sound":false}');
    file_put_contents($project . '/game.php', '<?php throw new RuntimeException("Discovery launched the game.");');
    file_put_contents($project . '/save.json', '{"player":"untouched"}');
    putenv('ICHILOTO_RENDERER=parent-choice');

    $absent = getReadOnlyAvailabilityReport($availability, $project, $root);
    assertAvailability($absent['schema'] === 'ichiloto.renderers/1' && $absent['preferredId'] === 'terminal'
        && $absent['renderers'][0]['available'] && ! $absent['renderers'][1]['available']
        && str_contains($absent['renderers'][1]['reason'], 'No installed renderer manifest'), 'Absent GPUI was not diagnosed.');

    file_put_contents($manifest, '{');
    $malformed = getReadOnlyAvailabilityReport($availability, $project, $root);
    assertAvailability($malformed['preferredId'] === 'terminal' && str_contains($malformed['renderers'][1]['reason'], 'Invalid installed'),
        'Malformed installation was not diagnosed.');
    $platform = Ichiloto\Engine\Rendering\Launch\PackagedRendererExecutableResolver::hostPlatform();
    file_put_contents($manifest, json_encode(['version' => 1, 'renderers' => ['gpui' => [$platform => 'renderer']]], JSON_THROW_ON_ERROR));
    $missing = getReadOnlyAvailabilityReport($availability, $project, $root);
    assertAvailability(! $missing['renderers'][1]['available'] && str_contains($missing['renderers'][1]['reason'], 'missing or not executable'),
        'Missing installed executable was not diagnosed.');
    $marker = $project . '/native-launched';
    file_put_contents($installed . '/renderer', "#!/bin/sh\ntouch " . escapeshellarg($marker) . "\n");
    chmod($installed . '/renderer', 0755);
    $available = getReadOnlyAvailabilityReport($availability, $project, $root);
    assertAvailability($available['preferredId'] === 'gpui' && $available['renderers'][1]['available']
        && $available['renderers'][1]['reason'] === null && ! is_file($marker), 'Available GPUI was launched or not preferred.');
    chmod($installed . '/renderer', 0644);
    assertAvailability(! getReadOnlyAvailabilityReport($availability, $project, $root)['renderers'][1]['available'], 'Non-executable GPUI was offered.');
    unlink($installed . '/renderer');
    symlink(PHP_BINARY, $installed . '/renderer');
    assertAvailability(! getReadOnlyAvailabilityReport($availability, $project, $root)['renderers'][1]['available'], 'Escaped executable link was offered.');

    $neverPrompt = static fn(): never => throw new RuntimeException('Explicit auto or headless default prompted.');
    $selector = new RendererSelector(new RendererRegistry(), $neverPrompt);
    assertAvailability($selector->resolve(' auto ', false, true, $available)->id === 'gpui', 'Auto did not select available GPUI.');
    assertAvailability($selector->resolve('auto', false, false, $absent)->id === 'terminal', 'Auto did not select Terminal when GPUI was absent.');
    assertAvailability($selector->resolve(null, false, false, $available)->id === 'terminal', 'Ordinary headless default changed.');
    assertAvailability($selector->resolve('gpui', false, false, $absent)->id === 'gpui', 'Explicit unavailable GPUI silently became Terminal.');
    assertAvailability($selector->resolve(null, true, false, $absent)->id === 'gpui', 'The explicit GPUI alias silently became Terminal.');
    assertAvailability($selector->resolve('auto', true, false, $available)->id === 'gpui', 'Agreeing auto and GPUI alias failed.');
    expectAvailabilityFailure(static fn() => $selector->resolve('auto', false, false), 'requires a project renderer availability report');
    expectAvailabilityFailure(static fn() => $selector->resolve('auto', true, false, $absent), 'conflicts');
    expectAvailabilityFailure(static fn() => $selector->resolve('unknown', false, false, $available), 'Unknown renderer');
    $promptCalls = 0;
    $prompted = new RendererSelector(new RendererRegistry(), static function (string $label, array $options) use (&$promptCalls): string {
        $promptCalls++;
        assertAvailability($options === ['terminal' => 'Native Terminal', 'gpui' => 'GPUI'], 'Normal prompt options changed.');
        return 'terminal';
    });
    assertAvailability($prompted->resolve(null, false, true, $available)->id === 'terminal' && $promptCalls === 1,
        'Ordinary interactive selection changed.');

    $tester = new CommandTester(new RendererListCommand());
    $snapshot = getAvailabilitySnapshot($root);
    $status = $tester->execute(['--directory' => $project, '--json' => true], ['interactive' => true]);
    assertAvailability($status === Command::SUCCESS && json_decode($tester->getDisplay(), true, flags: JSON_THROW_ON_ERROR)
        === getReadOnlyAvailabilityReport($availability, $project, $root), 'renderer:list did not return pure contract JSON.');
    assertAvailability(getAvailabilitySnapshot($root) === $snapshot, 'renderer:list changed files.');
    $status = $tester->execute(['--directory' => $project], ['interactive' => false]);
    assertAvailability($status === Command::SUCCESS && str_contains($tester->getDisplay(), 'Native Terminal (terminal): available')
        && str_contains($tester->getDisplay(), 'GPUI (gpui): unavailable'), 'Human discovery output omitted availability.');
    $cli = [PHP_BINARY, '-d', 'memory_limit=512M', dirname(__DIR__) . '/bin/ichiloto', 'renderer:list',
        '--directory=' . $project, '--json', '--no-interaction'];
    $response = $runner->run($cli, $root, timeoutSeconds: 5);
    assertAvailability(json_decode($response, true, flags: JSON_THROW_ON_ERROR)['preferredId'] === 'terminal',
        'The registered renderer:list CLI failed.');
    expectAvailabilityFailure(static fn() => $runner->run(array_replace($cli, [5 => '--directory=' . $root . '/missing']), $root),
        'Supply a valid project directory');

    // This project reports a different Engine registry despite Console already loading its local Engine.
    $custom = ['schema' => 'ichiloto.renderers/1', 'renderers' => [
        ['id' => 'canvas', 'displayName' => 'Project Canvas', 'available' => true, 'reason' => null],
    ], 'preferredId' => 'canvas'];
    writeAvailabilityReportFixture($autoload, $custom);
    assertAvailability(getReadOnlyAvailabilityReport($availability, $project, $root) === $custom, 'Console substituted its own Engine.');
    $customSelector = new RendererSelector(new RendererRegistry([new RendererDescriptor('canvas', 'Project Canvas')]), $neverPrompt);
    assertAvailability($customSelector->resolve('auto', false, false, $custom)->id === 'canvas', 'Custom registry preference was lost.');
    expectAvailabilityFailure(static fn() => $selector->resolve('auto', false, false, $custom), 'Unknown renderer');
    $empty = ['schema' => 'ichiloto.renderers/1', 'renderers' => [], 'preferredId' => null];
    writeAvailabilityReportFixture($autoload, $empty);
    assertAvailability(getReadOnlyAvailabilityReport($availability, $project, $root) === $empty, 'Empty valid registry was rejected.');
    expectAvailabilityFailure(static fn() => $selector->resolve('auto', false, false, $empty), 'No renderer is available');

    $invalidReports = [null, ['schema' => 'future'], $custom + ['extra' => true], array_replace($custom, ['schema' => 'ichiloto.renderers/2']),
        array_replace($custom, ['preferredId' => 'gpui']), array_replace($custom, ['preferredId' => null]),
        array_replace($custom, ['preferredId' => []]), array_replace($custom, ['renderers' => ['canvas' => $custom['renderers'][0]]]),
        array_replace($custom, ['renderers' => [$custom['renderers'][0], $custom['renderers'][0]]])];
    foreach ([['id' => 'UPPERCASE'], ['id' => str_repeat('x', 65)], ['displayName' => ''], ['displayName' => str_repeat('x', 129)],
        ['displayName' => "Canvas\x1b"], ['available' => 1], ['reason' => 'Available with reason'],
        ['available' => false], ['available' => false, 'reason' => ''], ['available' => false, 'reason' => str_repeat('x', 2049)],
        ['extra' => 1]] as $changes) {
        $invalidReports[] = array_replace($custom, ['renderers' => [array_replace($custom['renderers'][0], $changes)]]);
    }
    $tooMany = [];
    for ($index = 0; $index < 33; $index++) { $tooMany[] = array_replace($custom['renderers'][0], ['id' => 'canvas' . $index]); }
    $invalidReports[] = array_replace($custom, ['renderers' => $tooMany, 'preferredId' => 'canvas0']);
    foreach ($invalidReports as $report) {
        writeAvailabilityReportFixture($autoload, $report);
        expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'renderer');
    }
    foreach (['[]', '{"schema":"ichiloto.renderers/1","renderers":{},"preferredId":null}',
        '{"schema":"ichiloto.renderers/1","renderers":{"canvas":{"id":"canvas","displayName":"Canvas","available":true,"reason":null}},"preferredId":"canvas"}'] as $json) {
        file_put_contents($autoload, '<?php fwrite(STDOUT, ' . var_export($json, true) . '); exit(0);');
        expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'renderer');
    }
    file_put_contents($autoload, '<?php namespace Ichiloto\\Engine\\Rendering\\Launch; final class RendererRegistry {}');
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'does not support RendererRegistry::getReport');
    file_put_contents($autoload, '<?php');
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'does not support renderer availability');
    file_put_contents($autoload, '<?php echo "unexpected bootstrap output";');
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'does not support renderer availability');
    writeAvailabilityReportFixture($autoload, $custom);
    file_put_contents($autoload, str_replace('final class RendererRegistry', 'echo "{"; final class RendererRegistry',
        (string) file_get_contents($autoload)));
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'malformed renderer availability JSON');
    file_put_contents($autoload, '<?php echo str_repeat("x", 65537);');
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), '64 KiB output limit');
    file_put_contents($autoload, '<?php throw new RuntimeException("Project bootstrap failed.");');
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($availability, $project, $root), 'Project bootstrap failed');
    $delayedMarker = $root . '/timed-out-child-survived';
    file_put_contents($autoload, '<?php usleep(400000); file_put_contents(' . var_export($delayedMarker, true) . ', "survived");');
    $short = new ProjectRendererAvailability(timeoutSeconds: 0.1);
    expectAvailabilityFailure(static fn() => getReadOnlyAvailabilityReport($short, $project, $root), 'timed out');
    usleep(500000);
    assertAvailability(! is_file($delayedMarker), 'A timed-out discovery child survived cleanup.');
    unlink($autoload);
    expectAvailabilityFailure(static fn() => $availability->getReport($project), 'composer install');
    expectAvailabilityFailure(static fn() => $availability->getReport($root . '/missing'), 'valid project directory');
    assertAvailability(getcwd() === $workingDirectory && getenv('ICHILOTO_RENDERER') === 'parent-choice',
        'Discovery changed the parent working directory or renderer environment.');
} finally {
    putenv($rendererEnvironment === false ? 'ICHILOTO_RENDERER' : 'ICHILOTO_RENDERER=' . $rendererEnvironment);
    removeAvailabilityDirectory($root);
}

fwrite(STDOUT, "PASS: read-only project-authoritative renderer discovery, strict bounded reports, and explicit auto selection.\n");
