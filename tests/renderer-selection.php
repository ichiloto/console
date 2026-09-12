<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\PlayCommand;
use Ichiloto\Console\Renderer\RendererDescriptor;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Renderer\RendererSelector;
use Ichiloto\Console\Support\GameLaunchCommandBuilder;
use Ichiloto\Console\Support\TerminalInteractivity;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

require dirname(__DIR__) . '/vendor/autoload.php';

final class RendererSelectionTestFailure extends RuntimeException
{
}

function failRendererSelectionTest(string $message): never
{
    throw new RendererSelectionTestFailure($message);
}

function assertRendererSelection(bool $condition, string $message): void
{
    if (! $condition) {
        failRendererSelectionTest($message);
    }
}

/**
 * @param callable(string, array<string, string>): (int|string)|null $prompt
 */
function rendererTestCommand(
    ?callable $prompt = null,
    bool $inputIsTty = true,
    bool $outputIsTty = true,
): PlayCommand {
    $registry = new RendererRegistry();

    return new PlayCommand(
        rendererRegistry: $registry,
        rendererSelector: new RendererSelector($registry, $prompt),
        terminalInteractivity: new TerminalInteractivity(
            static fn (): bool => $inputIsTty,
            static fn (): bool => $outputIsTty,
        ),
        launchCommandBuilder: new GameLaunchCommandBuilder(),
    );
}

/**
 * @param array<string, mixed> $options
 * @return array{status: int, display: string}
 */
function runRendererPlayCommand(
    PlayCommand $command,
    string $projectDirectory,
    array $options = [],
    bool $interactive = true,
): array {
    $application = new Application();
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);
    $application->addCommand($command);

    $tester = new ApplicationTester($application);
    $status = $tester->run([
        'command' => 'play',
        '--directory' => $projectDirectory,
        '--no-tmux' => true,
        ...$options,
    ], [
        'interactive' => $interactive,
        'decorated' => false,
    ]);

    return [
        'status' => $status,
        'display' => $tester->getDisplay(true),
    ];
}

/** @return array{renderer: string|null, cwd: string|false} */
function readRendererLaunchResult(string $projectDirectory): array
{
    $resultFile = $projectDirectory . '/renderer-launch.json';

    if (! is_file($resultFile)) {
        failRendererSelectionTest('The fixture game was not launched.');
    }

    $result = json_decode((string) file_get_contents($resultFile), true, flags: JSON_THROW_ON_ERROR);

    if (! is_array($result)) {
        failRendererSelectionTest('The fixture game produced an invalid launch result.');
    }

    return [
        'renderer' => is_string($result['renderer'] ?? null) ? $result['renderer'] : null,
        'cwd' => is_string($result['cwd'] ?? null) ? $result['cwd'] : false,
    ];
}

function clearRendererLaunchResult(string $projectDirectory): void
{
    $resultFile = $projectDirectory . '/renderer-launch.json';

    if (is_file($resultFile)) {
        unlink($resultFile);
    }
}

function removeRendererTestDirectory(string $directory): void
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
            removeRendererTestDirectory($path);
        } else {
            unlink($path);
        }
    }

    rmdir($directory);
}

$registry = new RendererRegistry();
$ids = array_map(
    static fn (RendererDescriptor $renderer): string => $renderer->id,
    $registry->all(),
);

assertRendererSelection($ids === ['terminal', 'gpui'], 'The registry does not expose exactly terminal and gpui.');
assertRendererSelection(count($ids) === count(array_unique($ids)), 'Renderer IDs are not unique.');
assertRendererSelection($registry->find(' terminal ')?->displayName === 'Native Terminal', 'The terminal display name is incorrect.');
assertRendererSelection($registry->find('GPUI')?->displayName === 'GPUI', 'The GPUI display name is incorrect.');
assertRendererSelection($registry->options() === [
    'terminal' => 'Native Terminal',
    'gpui' => 'GPUI',
], 'Prompt options are not generated from the registered renderers.');

try {
    $registry->require('unknown');
    failRendererSelectionTest('An unknown renderer was accepted.');
} catch (InvalidArgumentException $exception) {
    assertRendererSelection(str_contains($exception->getMessage(), 'terminal'), 'The unknown-renderer error omits terminal.');
    assertRendererSelection(str_contains($exception->getMessage(), 'gpui'), 'The unknown-renderer error omits gpui.');
}

try {
    new RendererRegistry([
        new RendererDescriptor('terminal', 'Native Terminal'),
        new RendererDescriptor(' TERMINAL ', 'Duplicate Terminal'),
    ]);
    failRendererSelectionTest('A duplicate normalized renderer ID was accepted.');
} catch (InvalidArgumentException) {
}

$promptCalls = 0;
$noPrompt = static function () use (&$promptCalls): never {
    $promptCalls++;
    failRendererSelectionTest('Renderer selection prompted unexpectedly.');
};
$selector = new RendererSelector($registry, $noPrompt);

assertRendererSelection($selector->resolve('terminal', false, true)->id === 'terminal', '--renderer=terminal did not resolve terminal.');
assertRendererSelection($selector->resolve('gpui', false, true)->id === 'gpui', '--renderer=gpui did not resolve GPUI.');
assertRendererSelection($selector->resolve(null, true, true)->id === 'gpui', '--gpui-renderer did not resolve GPUI.');
assertRendererSelection($selector->resolve('gpui', true, true)->id === 'gpui', 'Agreeing GPUI options were rejected.');
assertRendererSelection($selector->resolve(null, false, false)->id === 'terminal', 'Non-interactive selection did not default to terminal.');
assertRendererSelection($promptCalls === 0, 'An explicit or non-interactive selection prompted.');

try {
    $selector->resolve('terminal', true, true);
    failRendererSelectionTest('Conflicting renderer options were accepted.');
} catch (InvalidArgumentException $exception) {
    assertRendererSelection(str_contains($exception->getMessage(), 'conflicts'), 'The renderer conflict error is unclear.');
}

$promptLabel = '';
$promptOptions = [];
$terminalSelector = new RendererSelector(
    $registry,
    static function (string $label, array $options) use (&$promptLabel, &$promptOptions): string {
        $promptLabel = $label;
        $promptOptions = $options;
        return 'terminal';
    },
);
assertRendererSelection($terminalSelector->resolve(null, false, true)->id === 'terminal', 'Interactive Native Terminal selection failed.');
assertRendererSelection($promptLabel === 'Select renderer', 'The interactive selector label is incorrect.');
assertRendererSelection($promptOptions === $registry->options(), 'The interactive selector options differ from the registry.');

$gpuiSelector = new RendererSelector(
    $registry,
    static fn (string $label, array $options): string => 'gpui',
);
assertRendererSelection($gpuiSelector->resolve(null, false, true)->id === 'gpui', 'Interactive GPUI selection failed.');

assertRendererSelection(
    ! (new TerminalInteractivity(static fn (): bool => false, static fn (): bool => true))->supportsPrompts(),
    'A non-TTY input was treated as prompt-capable.',
);
assertRendererSelection(
    ! (new TerminalInteractivity(static fn (): bool => true, static fn (): bool => false))->supportsPrompts(),
    'A non-TTY output was treated as prompt-capable.',
);
assertRendererSelection(
    (new TerminalInteractivity(static fn (): bool => true, static fn (): bool => true))->supportsPrompts(),
    'A fully interactive terminal was not treated as prompt-capable.',
);

$command = rendererTestCommand();
$definition = $command->getDefinition();
assertRendererSelection($definition->getOption('renderer')->isValueRequired(), '--renderer does not require a value.');
assertRendererSelection($definition->getOption('renderer')->getDefault() === null, '--renderer has an unexpected default.');
assertRendererSelection(! $definition->getOption('gpui-renderer')->acceptValue(), '--gpui-renderer is not a flag.');
assertRendererSelection(! $definition->getOption('no-tmux')->acceptValue(), '--no-tmux changed behavior.');
assertRendererSelection(! $definition->hasOption('renderer-path'), 'A renderer executable-path option was exposed.');
assertRendererSelection(! $definition->hasOption('gpui-path'), 'A GPUI executable-path option was exposed.');
assertRendererSelection(! $definition->hasOption('renderer-binary'), 'A renderer binary option was exposed.');

$builder = new GameLaunchCommandBuilder();
$gpuiGameCommand = $builder->buildGameCommand('/tmp/game.php', '/tmp/error.log', 'gpui');
$gpuiTmuxCommand = $builder->buildTmuxNewSessionCommand(
    'ichiloto-play-test',
    '/tmp/project',
    $builder->buildCrashPreservingCommand($gpuiGameCommand, 'Ichiloto game'),
);
assertRendererSelection(str_contains($gpuiGameCommand, "ICHILOTO_RENDERER='gpui'"), 'The direct command omits the GPUI launch intent.');
assertRendererSelection(str_contains($gpuiTmuxCommand, 'ICHILOTO_RENDERER='), 'The tmux command omits the renderer launch intent.');
assertRendererSelection(str_contains($gpuiTmuxCommand, 'gpui'), 'The tmux command omits the GPUI renderer ID.');

$testRoot = sys_get_temp_dir() . '/ichiloto-renderer-selection-' . bin2hex(random_bytes(8));
$projectDirectory = $testRoot . '/relative-project';
$appDirectory = $projectDirectory . '/app';
$vendorDirectory = $projectDirectory . '/vendor';
$spacedProjectDirectory = $testRoot . '/project with spaces';
$spacedAppDirectory = $spacedProjectDirectory . '/game files';
$spacedVendorDirectory = $spacedProjectDirectory . '/vendor';
$fakeTmuxDirectory = $testRoot . '/fake tmux bin';
$originalWorkingDirectory = getcwd();
$originalRendererEnvironment = getenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE);
$originalPathEnvironment = getenv('PATH');

mkdir($appDirectory, 0777, true);
mkdir($vendorDirectory, 0777, true);
mkdir($spacedAppDirectory, 0777, true);
mkdir($spacedVendorDirectory, 0777, true);
mkdir($spacedProjectDirectory . '/logs', 0777, true);
mkdir($fakeTmuxDirectory, 0777, true);

$projectConfig = json_encode([
    'main' => 'app/game.php',
    'debug' => ['enabled' => false, 'show' => false],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
file_put_contents($projectDirectory . '/ichiloto.json', $projectConfig);
file_put_contents($vendorDirectory . '/autoload.php', "<?php\n");
$fixtureGameSource = <<<'PHP'
<?php

declare(strict_types=1);

$projectDirectory = dirname(__DIR__);
file_put_contents($projectDirectory . '/renderer-launch.json', json_encode([
    'renderer' => getenv('ICHILOTO_RENDERER') ?: null,
    'cwd' => getcwd(),
], JSON_THROW_ON_ERROR));

if (is_file($projectDirectory . '/fail-launch')) {
    fwrite(STDERR, "fixture launch failure\n");
    exit(7);
}
PHP;
file_put_contents($appDirectory . '/game.php', $fixtureGameSource);

$spacedProjectConfig = json_encode([
    'main' => 'game files/game runner.php',
    'debug' => ['enabled' => false, 'show' => false],
], JSON_PRETTY_PRINT | JSON_THROW_ON_ERROR) . PHP_EOL;
file_put_contents($spacedProjectDirectory . '/ichiloto.json', $spacedProjectConfig);
file_put_contents($spacedVendorDirectory . '/autoload.php', "<?php\n");
file_put_contents($spacedAppDirectory . '/game runner.php', $fixtureGameSource);

$fakeTmuxSource = '#!' . PHP_BINARY . "\n" . <<<'PHP'
<?php

declare(strict_types=1);

$arguments = array_slice($argv, 1);
file_put_contents(dirname(__DIR__) . '/tmux-arguments.json', json_encode($arguments, JSON_THROW_ON_ERROR));
$launchCommand = $arguments[array_key_last($arguments)] ?? '';
passthru($launchCommand, $exitCode);
exit($exitCode);
PHP;
file_put_contents($fakeTmuxDirectory . '/tmux', $fakeTmuxSource);
chmod($fakeTmuxDirectory . '/tmux', 0755);

try {
    if ($originalWorkingDirectory === false || ! chdir($testRoot)) {
        failRendererSelectionTest('Could not enter the renderer fixture parent directory.');
    }

    putenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE);

    $neverPrompt = static function (): never {
        failRendererSelectionTest('An explicit or non-interactive play command prompted.');
    };

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'terminal'],
    );
    $launch = readRendererLaunchResult($projectDirectory);
    assertRendererSelection($result['status'] === Command::SUCCESS, '--renderer=terminal failed to launch.');
    assertRendererSelection($launch['renderer'] === 'terminal', 'The direct terminal child received the wrong renderer.');
    assertRendererSelection($launch['cwd'] === realpath($projectDirectory), 'A relative --directory did not launch from the project root.');
    assertRendererSelection(
        getenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE) === false,
        'A previously unset renderer environment leaked into the parent process.',
    );

    putenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE . '=parent-renderer');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'gpui'],
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, '--renderer=gpui failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'gpui', 'The direct GPUI child received the wrong renderer.');

    clearRendererLaunchResult($spacedProjectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'project with spaces',
        ['--renderer' => 'gpui'],
    );
    $spacedLaunch = readRendererLaunchResult($spacedProjectDirectory);
    assertRendererSelection($result['status'] === Command::SUCCESS, 'A shell-sensitive project path failed to launch.');
    assertRendererSelection($spacedLaunch['renderer'] === 'gpui', 'The spaced-path child received the wrong renderer.');
    assertRendererSelection($spacedLaunch['cwd'] === realpath($spacedProjectDirectory), 'The spaced project path did not become the child working directory.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--gpui-renderer' => true],
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, '--gpui-renderer failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'gpui', 'The GPUI alias passed the wrong renderer.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'gpui', '--gpui-renderer' => true],
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, 'Agreeing GPUI CLI options failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'gpui', 'Agreeing GPUI CLI options passed the wrong renderer.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'terminal', '--gpui-renderer' => true],
    );
    assertRendererSelection($result['status'] === Command::INVALID, 'Conflicting CLI renderer options did not fail.');
    assertRendererSelection(str_contains($result['display'], 'conflicts'), 'The CLI renderer conflict message is unclear.');
    assertRendererSelection(! is_file($projectDirectory . '/renderer-launch.json'), 'A conflicting renderer selection launched the game.');

    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'unknown'],
    );
    assertRendererSelection($result['status'] === Command::INVALID, 'An unknown CLI renderer did not fail.');
    assertRendererSelection(str_contains($result['display'], 'terminal, gpui'), 'The unknown CLI renderer error omits valid IDs.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--no-interaction' => true],
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, '--no-interaction failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'terminal', '--no-interaction did not default to terminal.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt, inputIsTty: true, outputIsTty: false),
        'relative-project',
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, 'Non-TTY output failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'terminal', 'Non-TTY output did not default to terminal.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt, inputIsTty: false, outputIsTty: true),
        'relative-project',
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, 'Non-TTY input failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'terminal', 'Non-TTY input did not default to terminal.');

    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'gpui', '--no-interaction' => true],
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, 'Explicit GPUI failed with --no-interaction.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'gpui', 'Explicit non-interactive GPUI passed the wrong renderer.');

    $interactivePromptCalls = 0;
    $interactivePromptOptions = [];
    $interactivePrompt = static function (string $label, array $options) use (&$interactivePromptCalls, &$interactivePromptOptions): string {
        $interactivePromptCalls++;
        $interactivePromptOptions = $options;
        return 'terminal';
    };
    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($interactivePrompt),
        'relative-project',
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, 'Interactive Native Terminal selection failed to launch.');
    assertRendererSelection($interactivePromptCalls === 1, 'Interactive play did not prompt exactly once.');
    assertRendererSelection($interactivePromptOptions === $registry->options(), 'Interactive play did not present registry options.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'terminal', 'Interactive Native Terminal passed the wrong renderer.');

    $interactiveGpuiPrompt = static fn (string $label, array $options): string => 'gpui';
    clearRendererLaunchResult($projectDirectory);
    $result = runRendererPlayCommand(
        rendererTestCommand($interactiveGpuiPrompt),
        'relative-project',
    );
    assertRendererSelection($result['status'] === Command::SUCCESS, 'Interactive GPUI selection failed to launch.');
    assertRendererSelection(readRendererLaunchResult($projectDirectory)['renderer'] === 'gpui', 'Interactive GPUI passed the wrong renderer.');

    putenv('PATH=' . $fakeTmuxDirectory . PATH_SEPARATOR . ($originalPathEnvironment === false ? '' : $originalPathEnvironment));

    foreach (['terminal', 'gpui'] as $rendererId) {
        clearRendererLaunchResult($spacedProjectDirectory);
        $gameCommand = $builder->buildGameCommand(
            $spacedAppDirectory . '/game runner.php',
            $spacedProjectDirectory . '/logs/error log.txt',
            $rendererId,
        );
        $launchCommand = $builder->buildCrashPreservingCommand($gameCommand, 'Ichiloto game');
        $tmuxCommand = $builder->buildTmuxNewSessionCommand(
            'ichiloto-play-spaced-project',
            $spacedProjectDirectory,
            $launchCommand,
        );
        passthru($tmuxCommand, $tmuxExitCode);

        assertRendererSelection($tmuxExitCode === 0, sprintf('The parsed tmux %s launch failed.', $rendererId));
        assertRendererSelection(
            readRendererLaunchResult($spacedProjectDirectory)['renderer'] === $rendererId,
            sprintf('The parsed tmux launch passed the wrong %s renderer intent.', $rendererId),
        );

        $tmuxArguments = json_decode(
            (string) file_get_contents($testRoot . '/tmux-arguments.json'),
            true,
            flags: JSON_THROW_ON_ERROR,
        );
        assertRendererSelection(is_array($tmuxArguments), 'The fake tmux process did not capture parsed arguments.');
        assertRendererSelection(($tmuxArguments[5] ?? null) === $spacedProjectDirectory, 'The tmux working directory was not shell-escaped intact.');
        assertRendererSelection(($tmuxArguments[6] ?? null) === $launchCommand, 'The tmux launch command was not parsed as one argument.');
    }

    if ($originalPathEnvironment === false) {
        putenv('PATH');
    } else {
        putenv('PATH=' . $originalPathEnvironment);
    }

    assertRendererSelection(
        getenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE) === 'parent-renderer',
        'Renderer selection leaked into the parent environment.',
    );
    assertRendererSelection(
        (string) file_get_contents($projectDirectory . '/ichiloto.json') === $projectConfig,
        'Renderer selection altered ichiloto.json.',
    );

    file_put_contents($projectDirectory . '/fail-launch', 'fail');
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'terminal'],
    );
    unlink($projectDirectory . '/fail-launch');
    assertRendererSelection($result['status'] === Command::FAILURE, 'A failing game did not return failure.');
    assertRendererSelection(str_contains($result['display'], 'exited unexpectedly'), 'The failing game omitted its error-log guidance.');
    assertRendererSelection(
        str_contains((string) file_get_contents($projectDirectory . '/logs/error.log'), 'fixture launch failure'),
        'The failing game did not append stderr to the error log.',
    );

    file_put_contents($projectDirectory . '/ichiloto.json', json_encode(['main' => 'app/missing.php'], JSON_THROW_ON_ERROR));
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'terminal'],
    );
    assertRendererSelection($result['status'] === Command::FAILURE, 'A missing main file did not fail.');
    assertRendererSelection(str_contains($result['display'], 'main file does not exist'), 'The missing-main error changed unexpectedly.');
    file_put_contents($projectDirectory . '/ichiloto.json', $projectConfig);

    unlink($vendorDirectory . '/autoload.php');
    $result = runRendererPlayCommand(
        rendererTestCommand($neverPrompt),
        'relative-project',
        ['--renderer' => 'terminal'],
    );
    assertRendererSelection($result['status'] === Command::FAILURE, 'Missing project dependencies did not fail.');
    assertRendererSelection(str_contains($result['display'], 'composer install'), 'The missing-dependencies guidance changed unexpectedly.');
} finally {
    if ($originalWorkingDirectory !== false) {
        chdir($originalWorkingDirectory);
    }

    if ($originalRendererEnvironment === false) {
        putenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE);
    } else {
        putenv(GameLaunchCommandBuilder::RENDERER_ENVIRONMENT_VARIABLE . '=' . $originalRendererEnvironment);
    }

    if ($originalPathEnvironment === false) {
        putenv('PATH');
    } else {
        putenv('PATH=' . $originalPathEnvironment);
    }

    removeRendererTestDirectory($testRoot);
}

fwrite(STDOUT, "PASS: play selects stable renderers and scopes launch intent to the child process.\n");
