<?php

declare(strict_types=1);

/**
 * A disposable project with just enough for ichiloto battle to set up a
 * party: a system file naming a starting party of two, the two actors, and
 * an item list with a weapon and a potion. Its engine is the console's own.
 */
function writeBattleTestProject(): string
{
    $root = sys_get_temp_dir() . '/ichiloto-battle-project-' . bin2hex(random_bytes(6));
    mkdir($root . '/vendor', 0o777, true);
    mkdir($root . '/assets/Data/Actors', 0o777, true);
    file_put_contents($root . '/ichiloto.json', json_encode(['name' => 'Arena Test']));
    file_put_contents($root . '/vendor/autoload.php', "<?php\n");
    file_put_contents($root . '/config.php', "<?php return [];\n");
    file_put_contents($root . '/assets/Data/enemies.php', "<?php return [];\n");
    file_put_contents($root . '/assets/Data/system.php', "<?php return ['title' => 'Arena Test', 'currency' => [],\n"
        . "  'startingPositions' => ['player' => []], 'startingParty' => ['hero', 'mage']];\n");
    foreach (['hero' => 'Hero', 'mage' => 'Mage'] as $id => $name) {
        file_put_contents($root . "/assets/Data/Actors/{$name}.php", '<?php return ' . var_export(['data' => [
            'id' => $id, 'name' => $name, 'currentExp' => 0, 'stats' => [
                'currentHp' => 40, 'currentMp' => 10, 'currentAp' => 3, 'totalHp' => 100, 'totalMp' => 20, 'totalAp' => 3,
                'attack' => 8, 'defence' => 7, 'magicAttack' => 6, 'magicDefence' => 5, 'speed' => 4, 'grace' => 3, 'evasion' => 2,
            ],
        ]], true) . ";\n");
    }
    file_put_contents($root . '/assets/Data/items.php', <<<'ITEMS'
    <?php
    use Ichiloto\Engine\Entities\Inventory\Items\Item;
    use Ichiloto\Engine\Entities\Inventory\Weapons\Weapon;
    return [
        new Item('Potion', '', '', 10, id: 'item.potion'),
        new Weapon('Iron Sword', '', '/', 50, id: 'equipment.iron-sword'),
    ];
    ITEMS);

    return $root;
}

/**
 * Gives the project a battle test loadout to grant: an ability and a spell,
 * and a story-gated summon only Hero may hold.
 */
function writeBattleLoadoutSources(string $root): void
{
    mkdir($root . '/assets/Data/Skills', 0o777, true);
    foreach ([['special', 'Test Strike', 2], ['special', 'Test Call', 4], ['magic', 'Test Flame', 3]] as $number => [$kind, $name, $cost]) {
        file_put_contents(sprintf('%s/assets/Data/Skills/%04d-%s.php', $root, $number + 1, strtolower(str_replace(' ', '-', $name))),
            "<?php\nreturn ['class' => \\Ichiloto\\Engine\\Entities\\Skills\\Skill::class, 'data' => " . var_export([
                'kind' => $kind, 'name' => $name, 'description' => '', 'icon' => '', 'cost' => $cost, 'cooldown' => 0, 'occasion' => 'Always',
                'scope' => ['side' => 'Enemy', 'number' => 'One', 'status' => 'Alive'],
                'invocation' => ['message' => '$1 casts $2!', 'speed' => 0, 'accuracy' => 0, 'repeat' => 1, 'apGain' => 10], 'effects' => [],
            ], true) . "];\n");
    }
    $directory = $root . '/assets/Cutscenes/Summons/test-call';
    mkdir($directory, 0o777, true);
    file_put_contents($directory . '/test-call.data.php', '<?php return ' . var_export([
        'id' => 'test-call', 'name' => 'Test Call', 'linkedActionId' => 'Test Call',
        'availability' => ['conditions' => [['type' => 'event', 'name' => 'unearned_story_unlock']]],
        'wielders' => ['mode' => 'characters', 'characters' => ['Hero'], 'tenancy' => 'exclusive'],
    ], true) . ";\n");
    file_put_contents($directory . '/test-call.timeline.php', "<?php return ['fps' => 12, 'lengthFrames' => 2, 'tracks' => [], 'cues' => []];\n");
}

function removeBattleTestProject(string $root): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
