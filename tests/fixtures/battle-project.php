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

function removeBattleTestProject(string $root): void
{
    $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST);
    foreach ($files as $file) {
        $file->isDir() ? rmdir($file->getPathname()) : unlink($file->getPathname());
    }
    rmdir($root);
}
