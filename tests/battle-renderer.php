<?php

declare(strict_types=1);

/**
 * Checks that `ichiloto battle` chooses its renderer as `play` does: from
 * --renderer, the --gpui-renderer alias or a prompt, handed to the arena
 * through the environment the engine reads, and given back afterwards. A
 * simulation draws nothing, so a renderer there is refused.
 */

use Ichiloto\Console\Commands\BattleCommand;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Renderer\RendererSelector;
use Ichiloto\Console\Support\SourceRendererUpdateChecker;
use Ichiloto\Console\Support\TerminalInteractivity;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/battle-project.php';

/** Records what the arena would have run with, instead of running it. */
#[AsCommand(name: 'battle')]
final class RecordingBattleCommand extends BattleCommand
{
    /** @var list<array{project: string, troop: string, renderer: string|false, setup: BattleTestSetup}> */
    public array $arenas = [];

    protected function runArena(string $projectName, string $troop, BattleTestSetup $setup): void
    {
        $this->arenas[] = ['project' => $projectName, 'troop' => $troop, 'renderer' => getenv('ICHILOTO_RENDERER'), 'setup' => $setup];
    }
}

function assertBattleRenderer(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @param array<string, mixed> $options @return array{0: RecordingBattleCommand, 1: int, 2: string} */
function runBattleRenderer(string $project, array $options, ?callable $prompt = null, bool $interactive = false): array
{
    $registry = new RendererRegistry();
    $command = new RecordingBattleCommand(
        rendererRegistry: $registry,
        rendererSelector: new RendererSelector($registry, $prompt),
        terminalInteractivity: new TerminalInteractivity(static fn (): bool => true, static fn (): bool => true),
        rendererUpdateChecker: new SourceRendererUpdateChecker(locateEngine: static fn (string $root): string => $root),
    );
    $application = new Application();
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);
    $application->addCommand($command);
    $tester = new ApplicationTester($application);
    $status = $tester->run(['command' => 'battle', '--directory' => $project, ...$options],
        ['interactive' => $interactive, 'decorated' => false]);

    return [$command, $status, $tester->getDisplay()];
}

$project = writeBattleTestProject();
putenv('ICHILOTO_RENDERER=outer');

try {
    [$command, $status] = runBattleRenderer($project, ['--renderer' => 'gpui', '--troop' => 'Bat x 2']);
    assertBattleRenderer($status === Command::SUCCESS, 'battle --renderer gpui succeeds');
    assertBattleRenderer(count($command->arenas) === 1 && $command->arenas[0]['project'] === 'Arena Test'
        && $command->arenas[0]['troop'] === 'Bat x 2' && $command->arenas[0]['renderer'] === 'gpui',
        'the arena runs once, with the chosen renderer in the environment');
    assertBattleRenderer(getenv('ICHILOTO_RENDERER') === 'outer', 'the caller\'s renderer environment is restored');

    [$command] = runBattleRenderer($project, ['--gpui-renderer' => true]);
    assertBattleRenderer(($command->arenas[0]['renderer'] ?? null) === 'gpui', '--gpui-renderer selects GPUI');

    [$command] = runBattleRenderer($project, [], static fn (string $label, array $options): string => 'terminal', interactive: true);
    assertBattleRenderer(($command->arenas[0]['renderer'] ?? null) === 'terminal', 'an interactive battle asks which renderer to use');

    [$command, $status, $display] = runBattleRenderer($project, ['--renderer' => 'nonsense']);
    assertBattleRenderer($status === Command::INVALID && $command->arenas === [], 'an unknown renderer is refused before the arena starts');

    [$command, $status, $display] = runBattleRenderer($project, ['--runs' => '3', '--renderer' => 'gpui']);
    assertBattleRenderer($status === Command::INVALID && str_contains($display, 'not to simulating one'),
        'a renderer with --runs is refused');
} finally {
    putenv('ICHILOTO_RENDERER');
    removeBattleTestProject($project);
}

fwrite(STDOUT, "PASS: battle selects its renderer as play does and scopes it to the arena.\n");
