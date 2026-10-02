<?php

declare(strict_types=1);

/**
 * Checks that `ichiloto battle` sets up its party as RPG Maker's Battle Test
 * does: --member chooses each actor, level and equipment, resolved by id or
 * name, the starting party stands in without it, and anything the party
 * cannot be built from is refused, naming every problem, before a battle
 * starts. Playing and --runs use the same setup.
 */

use Ichiloto\Console\Commands\BattleCommand;
use Ichiloto\Console\Renderer\RendererRegistry;
use Ichiloto\Console\Renderer\RendererSelector;
use Ichiloto\Console\Support\SourceRendererUpdateChecker;
use Ichiloto\Console\Support\TerminalInteractivity;
use Ichiloto\Engine\Scenes\Arena\BattleTestSetup;
use Ichiloto\Engine\Util\Config\ConfigStore;
use Symfony\Component\Console\Application;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\ApplicationTester;

require dirname(__DIR__) . '/vendor/autoload.php';
require __DIR__ . '/fixtures/battle-project.php';

/** Records the setup the arena would have run with, instead of running it. */
#[AsCommand(name: 'battle')]
final class SetupRecordingBattleCommand extends BattleCommand
{
    public ?BattleTestSetup $setup = null;

    protected function runArena(string $projectName, string $troop, BattleTestSetup $setup): void
    {
        $this->setup = $setup;
    }
}

function assertBattleMembers(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

/** @param list<string> $members @return array{0: SetupRecordingBattleCommand, 1: int, 2: string} */
function runBattleMembers(string $project, array $members, array $options = []): array
{
    // Each run reads its project afresh, as a separate ichiloto battle would.
    new ReflectionProperty(ConfigStore::class, 'store')->setValue(null, []);
    $registry = new RendererRegistry();
    $command = new SetupRecordingBattleCommand(
        rendererRegistry: $registry,
        rendererSelector: new RendererSelector($registry),
        terminalInteractivity: new TerminalInteractivity(static fn (): bool => false, static fn (): bool => false),
        rendererUpdateChecker: new SourceRendererUpdateChecker(locateEngine: static fn (string $root): string => $root),
    );
    $application = new Application();
    $application->setAutoExit(false);
    $application->setCatchExceptions(false);
    $application->addCommand($command);
    $tester = new ApplicationTester($application);
    // A renderer is for playing; --runs refuses one.
    $renderer = isset($options['--runs']) ? [] : ['--renderer' => 'terminal'];
    $status = $tester->run(['command' => 'battle', '--directory' => $project, ...$renderer,
        '--member' => $members, ...$options], ['interactive' => false, 'decorated' => false]);

    return [$command, $status, $tester->getDisplay()];
}

$project = writeBattleTestProject();

try {
    [$command, $status] = runBattleMembers($project, []);
    $members = array_map(static fn($member): array => [$member->actorId, $member->level], $command->setup?->members ?? []);
    assertBattleMembers($status === Command::SUCCESS && $members === [['hero', 1], ['mage', 1]],
        'without --member, the starting party is set up');

    [$command, $status] = runBattleMembers($project, ['Hero:20,Weapon=Iron Sword', 'mage']);
    $hero = $command->setup?->members[0];
    assertBattleMembers($status === Command::SUCCESS && $hero?->actorId === 'hero' && $hero->level === 20
        && $hero->equipment === ['Weapon' => 'equipment.iron-sword'] && $command->setup?->members[1]->level === 1,
        '--member sets each actor, level and equipment, by name or id');

    [$command, $status, $display] = runBattleMembers($project, ['ghost', 'Hero:20,Body=Iron Sword,Cape=Potion', 'mage:x']);
    assertBattleMembers($status === Command::INVALID && $command->setup === null
        && str_contains($display, 'the level must be a whole number')
        && str_contains($display, '--member ghost: the project has no such actor (its actors: hero, mage).')
        && str_contains($display, 'Iron Sword does not go in the Body slot.')
        && str_contains($display, 'has no Cape slot'),
        'every problem with the members is named, and no battle starts');

    [, $status, $display] = runBattleMembers($project, ['hero', 'hero', 'hero', 'hero', 'hero']);
    assertBattleMembers($status === Command::INVALID && str_contains($display, 'at most 4 members'),
        'more members than a party holds are refused');

    if (! method_exists(\Ichiloto\Engine\Scenes\Arena\BattleTestMember::class, 'withCommands')) {
        [, $status, $display] = runBattleMembers($project, ['Hero,Commands=attack|summon']);
        assertBattleMembers($status === Command::INVALID
            && str_contains($display, "this project's engine cannot set a member's commands, skills or summons; update its engine."),
            'an engine without test loadouts says so instead of ignoring them');
    }

    [, $status, $display] = runBattleMembers($project, ['ghost'], ['--runs' => '2']);
    assertBattleMembers($status === Command::INVALID && str_contains($display, 'no such actor'),
        '--runs refuses the same setup problems before simulating');
} finally {
    removeBattleTestProject($project);
}

fwrite(STDOUT, "PASS: battle sets up its party with --member, through the engine's battle test setup.\n");