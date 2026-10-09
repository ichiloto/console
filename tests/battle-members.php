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
writeBattleLoadoutSources($project);

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
    } else {
        [$command, $status] = runBattleMembers($project, ['Hero:5,Commands=skill|Magic|summon,Skills=Test Strike|Test Flame,Summons=test-call', 'mage']);
        $hero = $command->setup?->members[0];
        assertBattleMembers($status === Command::SUCCESS
            && array_map(static fn($type): string => $type->value, $hero?->commands ?? []) === ['skill', 'magic', 'summon']
            && $hero?->skills === ['Test Strike', 'Test Flame'] && $hero?->summons === ['test-call']
            && $command->setup?->members[1]->commands === null,
            'Commands, Skills and Summons reach the battle test setup, resolved by id or label');

        [$command, $status, $display] = runBattleMembers($project, ['Hero,Commands=dance,Skills=test flame|Nothing,Summons=nowhere']);
        assertBattleMembers($status === Command::INVALID && $command->setup === null
            && str_contains($display, '--member Hero: there is no command dance (commands: attack, skill, magic, summon, item, guard, escape).')
            && str_contains($display, '--member Hero: the project has no skill test flame (did you mean Test Flame?).')
            && str_contains($display, '--member Hero: the project has no skill Nothing.')
            && str_contains($display, '--member Hero: the project has no summon nowhere (summons: test-call).'),
            'every unknown command, skill and summon is named before a battle starts');

        [$command, $status] = runBattleMembers($project, ['Mage,Summons=test-call']);
        assertBattleMembers($status === Command::INVALID && $command->setup === null,
            "the engine refuses a summon the member may not hold, rather than granting it");

        [, $status, $display] = runBattleMembers($project, ['Hero,Skills=Test Strike'], ['--runs' => '2']);
        assertBattleMembers($status === Command::INVALID
            && str_contains($display, '--runs cannot use Commands, Skills or Summons')
            && str_contains($display, 'Leave out --runs to play the fight and use them'),
            '--runs refuses a loadout its attack-only simulator would never use');
    }

    [, $status, $display] = runBattleMembers($project, ['ghost'], ['--runs' => '2']);
    assertBattleMembers($status === Command::INVALID && str_contains($display, 'no such actor'),
        '--runs refuses the same setup problems before simulating');

    if (method_exists(BattleTestSetup::class, 'withArena')) {
        [$command, $status, $display] = runBattleMembers($project, [], ['--arena' => 'arena.lake', '--renderer' => 'gpui']);
        assertBattleMembers($status === Command::INVALID && $command->setup === null
            && str_contains($display, '--arena: the project declares no battle presentation, so it has no arenas.'),
            'an arena is refused in a project without a battle presentation');

        @mkdir($project . '/assets/Data/Presentation', 0o777, true);
        file_put_contents($project . '/assets/Data/Presentation/battle.php', <<<'PHP'
<?php
use Ichiloto\Engine\Battle\Presentation\BattleArenaDefinition;
use Ichiloto\Engine\Battle\Presentation\BattlePresentationCatalog;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasImage;
use Ichiloto\Engine\Rendering\Presentation\Canvas\CanvasRectangle;

$arena = static fn(string $name): BattleArenaDefinition => new BattleArenaDefinition($name,
    new CanvasImage('arena', 'Graphics/Battlebacks/' . $name . '.png', new CanvasRectangle(0, 0, 1350, 720)));
return new BattlePresentationCatalog(arenas: ['arena.road' => $arena('Road'), 'arena.lake' => $arena('Lake')], actors: [], enemies: []);
PHP);

        [$command, $status] = runBattleMembers($project, [], ['--arena' => 'arena.lake', '--renderer' => 'gpui']);
        assertBattleMembers($status === Command::SUCCESS && $command->setup?->arena === 'arena.lake',
            '--arena sets the arena the battle test fights in');

        [$command, $status, $display] = runBattleMembers($project, [], ['--arena' => 'arena.moon', '--renderer' => 'gpui']);
        assertBattleMembers($status === Command::INVALID && $command->setup === null
            && str_contains($display, '--arena: the project has no arena arena.moon (its arenas: arena.road (Road), arena.lake (Lake)).'),
            'an arena the presentation does not declare is refused, naming the ones it does');

        [, $status, $display] = runBattleMembers($project, [], ['--arena' => 'arena.lake']);
        assertBattleMembers($status === Command::INVALID && str_contains($display, 'the terminal renderer draws none'),
            'the terminal renderer refuses an arena it would not draw');

        [, $status, $display] = runBattleMembers($project, [], ['--arena' => 'arena.lake', '--runs' => '2']);
        assertBattleMembers($status === Command::INVALID && str_contains($display, 'An arena applies to playing a battle'),
            '--runs refuses an arena nothing would draw');

        if (class_exists(\Ichiloto\Engine\Scenes\Arena\ProjectBattleTest::class)) {
            // The project keeps a battle test in its system data, as RPG Maker keeps its Battle Test party.
            $system = $project . '/assets/Data/system.php';
            $original = (string) file_get_contents($system);
            file_put_contents($system, "<?php return ['title' => 'Arena Test', 'currency' => [],\n"
                . "  'startingPositions' => ['player' => []], 'startingParty' => ['hero', 'mage'],\n"
                . "  'battleTest' => ['troop' => 'Rats', 'arena' => 'arena.road', 'members' => [['actor' => 'mage', 'level' => 6]]]];\n");

            [$command, $status] = runBattleMembers($project, [], ['--renderer' => 'gpui']);
            assertBattleMembers($status === Command::SUCCESS
                && array_map(static fn($member): array => [$member->actorId, $member->level], $command->setup?->members ?? []) === [['mage', 6]]
                && $command->setup?->arena === 'arena.road',
                "without --member, the project's battle test party and arena are set up");

            [$command, $status] = runBattleMembers($project, ['hero'], ['--renderer' => 'gpui']);
            assertBattleMembers($status === Command::SUCCESS && $command->setup?->members[0]->actorId === 'hero'
                && $command->setup?->arena === 'arena.road',
                "--member replaces only the project's battle test party, not its arena");

            [$command, $status] = runBattleMembers($project, [], ['--arena' => 'arena.lake', '--renderer' => 'gpui']);
            assertBattleMembers($status === Command::SUCCESS && $command->setup?->members[0]->actorId === 'mage'
                && $command->setup?->arena === 'arena.lake',
                "--arena replaces only the project's battle test arena, not its party");

            file_put_contents($system, $original);
        }
    }
} finally {
    removeBattleTestProject($project);
}

fwrite(STDOUT, "PASS: battle sets up its party with --member, through the engine's battle test setup.\n");