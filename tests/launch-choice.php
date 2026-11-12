<?php

declare(strict_types=1);
require_once __DIR__ . '/fixtures/temporary.php';

/**
 * `play`, `battle` and `edit` choose what to open the same way: with no
 * choice flag an interactive terminal lists the choices, showing why any is
 * unavailable; `--gui` (or its `--gpui-renderer` alias) and `--renderer=<id>`
 * open a choice directly; `--gui` with `--renderer=terminal` is refused; and
 * a run without a terminal opens the terminal choice. Nothing real opens:
 * the game is a stub that records its renderer, the arena and both editors
 * are recorded instead of run.
 */

use Ichiloto\Console\Commands\BattleCommand;
use Ichiloto\Console\Commands\EditCommand;
use Ichiloto\Console\Commands\PlayCommand;
use Ichiloto\Console\Launch\EditorLaunchTargets;
use Ichiloto\Console\Launch\LaunchAvailability;
use Ichiloto\Console\Launch\LaunchRequest;
use Ichiloto\Console\Launch\LaunchTargetSelector;
use Ichiloto\Console\Launch\LaunchTargetSource;
use Ichiloto\Console\Launch\RendererLaunchTargets;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Support\GuiEditorLocator;
use Ichiloto\Console\Support\SourceRendererUpdateChecker;
use Ichiloto\Console\Support\TerminalInteractivity;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/battle-project.php';

function assertLaunchChoice(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @return string The exception message, failing when `$action` does not throw. */
function expectLaunchRefusal(callable $action, string $message): string
{
    try {
        $action();
    } catch (Throwable $exception) {
        assertLaunchChoice(str_contains($exception->getMessage(), $message), 'Unexpected refusal: ' . $exception->getMessage());
        return $exception->getMessage();
    }

    assertLaunchChoice(false, 'Expected a refusal: ' . $message);
}

/** Records the prompt each command shows, answering with `$answer`. */
final class RecordingPrompt
{
    public int $calls = 0;
    public string $label = '';
    /** @var array<string, string> */
    public array $options = [];
    public string $notice = '';
    /** @var array<string, ?string> What validation said about each option. */
    public array $validation = [];

    public function __construct(private readonly string $answer)
    {
    }

    /** @param array<string, string> $options */
    public function __invoke(string $label, array $options, Closure $validate, string $notice): string
    {
        $this->calls++;
        $this->label = $label;
        $this->options = $options;
        $this->notice = $notice;
        $this->validation = array_map($validate, array_combine(array_keys($options), array_keys($options)));

        return $this->answer;
    }
}

/** Renderers whose availability the test names, or cannot read when `$unavailable` is null. */
function getStubRendererTargets(?array $unavailable, ?string $preferredId = 'terminal'): LaunchTargetSource
{
    return new class ($unavailable, $preferredId) implements LaunchTargetSource {
        public string $promptLabel = 'Select renderer';

        public function __construct(private ?array $unavailable, private ?string $preferredId)
        {
        }

        public function getTargetNames(): array
        {
            return new RendererRegistry()->options();
        }

        public function readAvailability(string $projectDirectory): LaunchAvailability
        {
            return $this->unavailable === null
                ? throw new RuntimeException('The project Engine does not support renderer availability discovery.')
                : new LaunchAvailability($this->unavailable, $this->preferredId);
        }
    };
}

$never = static function (): never {
    assertLaunchChoice(false, 'A choice flag or a run without a terminal prompted.');
};
$select = static fn (LaunchTargetSelector $selector, LaunchTargetSource $targets, ?string $id, array $flags, bool $canPrompt): string
    => $selector->selectTarget(new LaunchRequest($id, $flags), $targets, \getConsoleTestTemporaryDirectory(), $canPrompt);

$workspace = \getConsoleTestTemporaryDirectory() . '/ichiloto-launch-choice-' . bin2hex(random_bytes(6));
$consoleRoot = $workspace . '/console';
$guiBuild = $workspace . '/gui-editor/target/release/' . GuiEditorLocator::BINARY;
mkdir($consoleRoot, 0777, true);
$locator = new GuiEditorLocator($consoleRoot, platform: 'Linux');
$battleProject = writeBattleTestProject();

try {
    // The shared selector, for renderers.
    $gpuiMissing = getStubRendererTargets(['gpui' => "No installed renderer manifest.\nLooked in resources."]);
    $selector = new LaunchTargetSelector($never);
    assertLaunchChoice($select($selector, $gpuiMissing, null, ['--gui'], true) === 'gpui', '--gui opens GPUI directly.');
    assertLaunchChoice($select($selector, $gpuiMissing, null, ['--gpui-renderer'], true) === 'gpui', '--gpui-renderer still opens GPUI.');
    assertLaunchChoice($select($selector, $gpuiMissing, 'GPUI', ['--gui', '--gpui-renderer'], true) === 'gpui', 'Agreeing flags open GPUI.');
    assertLaunchChoice($select($selector, $gpuiMissing, null, [], false) === 'terminal', 'A run without a terminal opens Terminal.');
    $conflict = expectLaunchRefusal(static fn () => $select($selector, $gpuiMissing, 'terminal', ['--gui'], true), 'conflicts');
    assertLaunchChoice($conflict === 'The --gui option conflicts with --renderer=terminal.', 'The --gui conflict names both flags.');
    $conflict = expectLaunchRefusal(static fn () => $select($selector, $gpuiMissing, 'terminal', ['--gpui-renderer'], true), 'conflicts');
    assertLaunchChoice($conflict === 'The --gpui-renderer alias conflicts with --renderer=terminal.', 'The alias conflict names the alias.');
    expectLaunchRefusal(static fn () => $select($selector, $gpuiMissing, 'auto', ['--gui'], true), 'selecting terminal');
    expectLaunchRefusal(static fn () => $select($selector, $gpuiMissing, 'canvas', [], true), 'Valid renderer IDs: terminal, gpui.');

    $prompt = new RecordingPrompt('terminal');
    assertLaunchChoice($select(new LaunchTargetSelector($prompt), $gpuiMissing, null, [], true) === 'terminal', 'The list answers Terminal.');
    assertLaunchChoice($prompt->calls === 1 && $prompt->label === 'Select renderer', 'No flag in a terminal shows the renderer list.');
    assertLaunchChoice($prompt->options === ['terminal' => 'Native Terminal', 'gpui' => 'GPUI (unavailable)']
        && $prompt->notice === "GPUI is unavailable: No installed renderer manifest.\nLooked in resources.",
        'An unavailable renderer stays in the list, with its whole reason above it.');
    assertLaunchChoice($prompt->validation === ['terminal' => null, 'gpui' => 'GPUI is unavailable.'],
        'Choosing an unavailable renderer says why and keeps the list open.');
    expectLaunchRefusal(static fn () => $select(new LaunchTargetSelector(new RecordingPrompt('gpui')), $gpuiMissing, null, [], true),
        "GPUI is unavailable: No installed renderer manifest.\nLooked in resources.");

    $prompt = new RecordingPrompt('gpui');
    assertLaunchChoice($select(new LaunchTargetSelector($prompt), getStubRendererTargets(null), null, [], true) === 'gpui',
        'Unreadable availability still lets the list choose.');
    assertLaunchChoice($prompt->options === ['terminal' => 'Native Terminal', 'gpui' => 'GPUI']
        && $prompt->notice === 'Availability could not be checked: The project Engine does not support renderer availability discovery.',
        'Unreadable availability is said in the list, not guessed.');
    expectLaunchRefusal(static fn () => $select($selector, getStubRendererTargets(null), 'auto', [], true), 'does not support renderer availability');

    // A report that leaves a registered renderer out marks it unavailable.
    $leftOut = new RendererLaunchTargets()->readReport(['schema' => 'ichiloto.renderers/1', 'renderers' => [
        ['id' => 'terminal', 'displayName' => 'Native Terminal', 'available' => true, 'reason' => null],
    ], 'preferredId' => 'terminal']);
    assertLaunchChoice($leftOut->getUnavailableReason('gpui') === "The project's Engine does not offer the GPUI renderer."
        && $leftOut->getUnavailableReason('terminal') === null, 'A renderer the Engine does not offer is unavailable.');

    // The same selector, for editors.
    $locatorEditors = new EditorLaunchTargets($locator);
    $prompt = new RecordingPrompt('terminal');
    assertLaunchChoice($select(new LaunchTargetSelector($prompt), $locatorEditors, null, [], true) === 'terminal', 'The editor list answers.');
    assertLaunchChoice($prompt->label === 'Select editor' && array_keys($prompt->options) === ['terminal', 'gpui']
        && $prompt->options['terminal'] === 'Terminal editor'
        && $prompt->options['gpui'] === 'GPUI editor (unavailable)'
        && $prompt->notice === 'GPUI editor is unavailable: ' . $locator->describeMissing(),
        'An unbuilt GPUI editor stays in the editor list with its reason.');
    assertLaunchChoice($select($selector, $locatorEditors, 'auto', [], true) === 'terminal', 'auto opens the Terminal editor before a build.');
    assertLaunchChoice($select($selector, $locatorEditors, null, [], false) === 'terminal', 'An editor run without a terminal opens the Terminal editor.');

    is_dir(dirname($guiBuild)) || mkdir(dirname($guiBuild), 0777, true);
    file_put_contents($guiBuild, "#!/bin/sh\nexit 0\n");
    chmod($guiBuild, 0755);
    assertLaunchChoice($select($selector, new EditorLaunchTargets($locator), 'auto', [], true) === 'gpui', 'auto prefers the built GPUI editor.');
    $prompt = new RecordingPrompt('gpui');
    assertLaunchChoice($select(new LaunchTargetSelector($prompt), new EditorLaunchTargets($locator), null, [], true) === 'gpui'
        && $prompt->options === ['terminal' => 'Terminal editor', 'gpui' => 'GPUI editor'] && $prompt->notice === '',
        'A built GPUI editor is offered plainly.');
    unlink($guiBuild);

    // edit, through the command.
    $editProject = $workspace . '/project';
    mkdir($editProject . '/vendor', 0777, true);
    file_put_contents($editProject . '/vendor/autoload.php', "<?php\n");
    file_put_contents($editProject . '/ichiloto.json', json_encode(['format' => Ichiloto\Engine\Core\ProjectFormat::CURRENT, 'main' => 'game.php'],
        JSON_THROW_ON_ERROR));

    $runEdit = static function (array $options, callable $prompt, bool $tty = true, bool $interactive = true) use ($editProject, $locator): array {
        $command = new #[AsCommand(name: 'edit')] class (new LaunchTargetSelector($prompt),
            new TerminalInteractivity(static fn (): bool => $tty, static fn (): bool => $tty), $locator) extends EditCommand {
            /** @var list<array{0: string, 1: string}> */
            public array $opened = [];

            protected function openTerminalEditor(string $workingDirectory, bool $noTmux, Symfony\Component\Console\Output\OutputInterface $output): int
            {
                $this->opened[] = ['terminal', $workingDirectory];
                return Command::SUCCESS;
            }

            protected function openGraphicalEditor(string $executable, string $project): int
            {
                $this->opened[] = ['gpui', $executable];
                return Command::SUCCESS;
            }
        };
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand($command);
        $tester = new ApplicationTester($application);
        $status = $tester->run(['command' => 'edit', '--directory' => $editProject, ...$options],
            ['interactive' => $interactive, 'decorated' => false]);

        return [$status, $command->opened, $tester->getDisplay(true)];
    };

    $prompt = new RecordingPrompt('terminal');
    [$status, $opened] = $runEdit([], $prompt);
    assertLaunchChoice($status === Command::SUCCESS && $opened === [['terminal', $editProject]] && $prompt->calls === 1
        && $prompt->label === 'Select editor' && $prompt->options['gpui'] === 'GPUI editor (unavailable)',
        'edit with no flag lists the editors and opens the chosen one.');
    [$status, $opened] = $runEdit([], $never, interactive: false);
    assertLaunchChoice($status === Command::SUCCESS && $opened === [['terminal', $editProject]], 'edit --no-interaction opens the Terminal editor.');
    [$status, $opened] = $runEdit([], $never, tty: false);
    assertLaunchChoice($status === Command::SUCCESS && $opened === [['terminal', $editProject]], 'edit without a terminal opens the Terminal editor.');
    [$status, $opened, $display] = $runEdit(['--gui' => true], $never);
    assertLaunchChoice($status === Command::FAILURE && $opened === [] && str_contains($display, 'The GUI editor is not built'),
        'edit --gui says the GPUI editor is not built and opens nothing.');
    [$status, $opened, $display] = $runEdit(['--gui' => true, '--renderer' => 'terminal'], $never);
    assertLaunchChoice($status === Command::INVALID && $opened === [] && str_contains($display, 'The --gui option conflicts with --renderer=terminal.'),
        'edit refuses --gui with --renderer=terminal.');
    [$status, $opened] = $runEdit(['--renderer' => 'terminal'], $never);
    assertLaunchChoice($status === Command::SUCCESS && $opened === [['terminal', $editProject]], 'edit --renderer=terminal opens directly.');
    [$status, $opened, $display] = $runEdit(['--renderer' => 'canvas'], $never);
    assertLaunchChoice($status === Command::INVALID && $opened === [] && str_contains($display, 'Valid renderer IDs: terminal, gpui.'),
        'edit refuses an unknown renderer.');

    is_dir(dirname($guiBuild)) || mkdir(dirname($guiBuild), 0777, true);
    file_put_contents($guiBuild, "#!/bin/sh\nexit 0\n");
    chmod($guiBuild, 0755);
    foreach ([['--gui' => true], ['--renderer' => 'gpui'], ['--gpui-renderer' => true], ['--renderer' => 'auto']] as $flags) {
        [$status, $opened] = $runEdit($flags, $never);
        assertLaunchChoice($status === Command::SUCCESS && $opened === [['gpui', $guiBuild]],
            'edit ' . array_key_first($flags) . ' opens the built GPUI editor directly.');
    }
    $prompt = new RecordingPrompt('gpui');
    [$status, $opened] = $runEdit([], $prompt);
    assertLaunchChoice($status === Command::SUCCESS && $opened === [['gpui', $guiBuild]], 'The editor list opens the GPUI editor.');

    // play, through the command, with a stub game that records its renderer.
    $playProject = $workspace . '/play';
    mkdir($playProject . '/vendor', 0777, true);
    file_put_contents($playProject . '/ichiloto.json', json_encode(['format' => Ichiloto\Engine\Core\ProjectFormat::CURRENT, 'main' => 'game.php'],
        JSON_THROW_ON_ERROR));
    file_put_contents($playProject . '/vendor/autoload.php', '<?php namespace Ichiloto\\Engine\\Rendering\\Launch; final class RendererRegistry {'
        . ' public function getReport() { return ' . var_export(['schema' => 'ichiloto.renderers/1', 'renderers' => [
            ['id' => 'terminal', 'displayName' => 'Native Terminal', 'available' => true, 'reason' => null],
            ['id' => 'gpui', 'displayName' => 'GPUI', 'available' => false, 'reason' => 'GPUI is not installed for this platform.'],
        ], 'preferredId' => 'terminal'], true) . '; } }');
    file_put_contents($playProject . '/game.php', '<?php file_put_contents(__DIR__ . "/launched", (string) getenv("ICHILOTO_RENDERER"));');
    $runPlay = static function (array $options, callable $prompt, bool $interactive = true) use ($playProject): array {
        @unlink($playProject . '/launched');
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand(new PlayCommand(
            launchTargetSelector: new LaunchTargetSelector($prompt),
            terminalInteractivity: new TerminalInteractivity(static fn (): bool => true, static fn (): bool => true),
            rendererUpdateChecker: new SourceRendererUpdateChecker(locateEngine: static fn (string $project): string => $project),
        ));
        $tester = new ApplicationTester($application);
        $status = $tester->run(['command' => 'play', '--directory' => $playProject, '--no-tmux' => true, ...$options],
            ['interactive' => $interactive, 'decorated' => false]);

        return [$status, is_file($playProject . '/launched') ? file_get_contents($playProject . '/launched') : null, $tester->getDisplay(true)];
    };

    $prompt = new RecordingPrompt('terminal');
    [$status, $launched] = $runPlay([], $prompt);
    assertLaunchChoice($status === Command::SUCCESS && $launched === 'terminal' && $prompt->calls === 1
        && $prompt->options === ['terminal' => 'Native Terminal', 'gpui' => 'GPUI (unavailable)']
        && $prompt->notice === 'GPUI is unavailable: GPUI is not installed for this platform.',
        'play with no flag lists the renderers the project reports, with the reason GPUI is unavailable.');
    [$status, $launched] = $runPlay(['--gui' => true], $never);
    assertLaunchChoice($status === Command::SUCCESS && $launched === 'gpui', 'play --gui opens GPUI directly, and never quietly Terminal.');
    [$status, $launched] = $runPlay([], $never, interactive: false);
    assertLaunchChoice($status === Command::SUCCESS && $launched === 'terminal', 'play --no-interaction opens Terminal.');
    [$status, $launched, $display] = $runPlay(['--gui' => true, '--renderer' => 'terminal'], $never);
    assertLaunchChoice($status === Command::INVALID && $launched === null && str_contains($display, 'The --gui option conflicts with --renderer=terminal.'),
        'play refuses --gui with --renderer=terminal.');

    // battle, through the command, with the arena recorded instead of run.
    $runBattle = static function (array $options, callable $prompt, ?LaunchTargetSource $targets = null, bool $interactive = true) use ($battleProject): array {
        $command = new #[AsCommand(name: 'battle')] class (
            rendererTargets: $targets ?? getStubRendererTargets(['gpui' => 'GPUI is not installed for this platform.']),
            launchTargetSelector: new LaunchTargetSelector($prompt),
            terminalInteractivity: new TerminalInteractivity(static fn (): bool => true, static fn (): bool => true),
            rendererUpdateChecker: new SourceRendererUpdateChecker(locateEngine: static fn (string $root): string => $root),
        ) extends BattleCommand {
            /** @var list<string|false> */
            public array $renderers = [];

            protected function runArena(string $projectName, string $troop, BattleTestSetup $setup): void
            {
                $this->renderers[] = getenv('ICHILOTO_RENDERER');
            }
        };
        $application = new Application();
        $application->setAutoExit(false);
        $application->setCatchExceptions(false);
        $application->addCommand($command);
        $tester = new ApplicationTester($application);
        $status = $tester->run(['command' => 'battle', '--directory' => $battleProject, '--troop' => 'Bat x 2', ...$options],
            ['interactive' => $interactive, 'decorated' => false]);

        return [$status, $command->renderers, $tester->getDisplay(true)];
    };

    $prompt = new RecordingPrompt('terminal');
    [$status, $renderers] = $runBattle([], $prompt);
    assertLaunchChoice($status === Command::SUCCESS && $renderers === ['terminal'] && $prompt->calls === 1
        && $prompt->options['gpui'] === 'GPUI (unavailable)'
        && $prompt->notice === 'GPUI is unavailable: GPUI is not installed for this platform.',
        'battle with no flag lists the renderers with the reason GPUI is unavailable.');
    [$status, $renderers] = $runBattle(['--gui' => true], $never);
    assertLaunchChoice($status === Command::SUCCESS && $renderers === ['gpui'], 'battle --gui opens GPUI directly.');
    [$status, $renderers] = $runBattle([], $never, interactive: false);
    assertLaunchChoice($status === Command::SUCCESS && $renderers === ['terminal'], 'battle --no-interaction opens Terminal.');
    [$status, $renderers, $display] = $runBattle(['--gui' => true, '--renderer' => 'terminal'], $never);
    assertLaunchChoice($status === Command::INVALID && $renderers === [] && str_contains($display, 'The --gui option conflicts with --renderer=terminal.'),
        'battle refuses --gui with --renderer=terminal.');
    [$status, $renderers] = $runBattle(['--renderer' => 'auto'], $never,
        getStubRendererTargets([], 'gpui'));
    assertLaunchChoice($status === Command::SUCCESS && $renderers === ['gpui'], 'battle --renderer=auto opens the preferred renderer.');
    foreach (['--gui', '--gpui-renderer'] as $flag) {
        [$status, $renderers, $display] = $runBattle(['--runs' => '3', $flag => true], $never);
        assertLaunchChoice($status === Command::INVALID && $renderers === [] && str_contains($display, 'not to simulating one'),
            "battle --runs refuses {$flag}.");
    }

    // The three commands define the same choice options.
    $editDefinition = new EditCommand(guiEditorLocator: $locator)->getDefinition();
    foreach ([new PlayCommand()->getDefinition(), new BattleCommand()->getDefinition(), $editDefinition] as $definition) {
        assertLaunchChoice($definition->getOption('renderer')->isValueRequired() && ! $definition->getOption('gui')->acceptValue()
            && ! $definition->getOption('gpui-renderer')->acceptValue(), 'Every launching command takes --renderer, --gui and --gpui-renderer.');
    }
} finally {
    putenv('ICHILOTO_RENDERER');
    removeBattleTestProject($battleProject);
    exec('rm -rf ' . escapeshellarg($workspace));
}

fwrite(STDOUT, "PASS: play, battle and edit list their choices, open --gui directly and refuse conflicts the same way.\n");
