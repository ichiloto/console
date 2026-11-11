<?php

declare(strict_types=1);

namespace Ichiloto\Console\Tests\Fixtures;

use Ichiloto\Console\Support\NewProjectScaffolder;
use Ichiloto\Console\Tests\Support\TemporaryFixtures;
use Ichiloto\Engine\Entities\Enemies\Enemy;
use RuntimeException;

require_once __DIR__ . '/temporary.php';

/** A source-only project; integration checks never borrow a player's files. */
final class IntegrationProject
{
    public const string ENEMY_NAME = 'Fixture Foe';
    public const string TROOP_NAME = 'Fixture Pair';

    public static function createProject(string $prefix): string
    {
        $root = TemporaryFixtures::createDirectory($prefix);
        new NewProjectScaffolder()->scaffold([
            'displayName' => 'Console Fixture',
            'directoryName' => 'console-fixture',
            'targetDirectory' => $root,
            'heroName' => 'Hero',
            'heroId' => 'Hero',
            'battleEngine' => 'traditional',
            'titleArt' => "CONSOLE FIXTURE\n",
        ]);
        $hero = require $root . '/assets/Data/Actors/Hero.php';
        $mage = $hero;
        $mage['data']['id'] = 'Mage';
        $mage['data']['name'] = 'Mage';
        self::writeArray($root . '/assets/Data/Actors/Mage.php', $mage);
        $system = require $root . '/assets/Data/system.php';
        $system['startingParty'] = ['Hero', 'Mage'];
        self::writeArray($root . '/assets/Data/system.php', $system);
        mkdir($root . '/assets/Data/Enemies');
        mkdir($root . '/assets/Graphics/Enemies');
        file_put_contents($root . '/assets/Graphics/Enemies/fixture-foe.txt', "(o)\n");
        self::writeArray($root . '/assets/Data/Enemies/0001-foe.php', [
            'class' => Enemy::class,
            'data' => [
                'name' => self::ENEMY_NAME, 'level' => 1, 'imagePath' => 'fixture-foe',
                'stats' => [
                    'maxHp' => 40, 'maxMp' => 5, 'attack' => 6, 'defence' => 4,
                    'magicAttack' => 3, 'magicDefence' => 4, 'speed' => 6, 'grace' => 2, 'evasion' => 1,
                ],
                'rewards' => ['experience' => 1, 'gold' => 1],
            ],
        ]);
        self::writeArray($root . '/assets/Data/troops.php', [[
            'name' => self::TROOP_NAME,
            'enemies' => [
                ['enemy' => self::ENEMY_NAME, 'position' => [2, 2]],
                ['enemy' => self::ENEMY_NAME, 'position' => [2, 6]],
            ],
        ]]);
        $composer = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
        $composer['autoload']['psr-4']['ConsoleFixture\\'] = 'src/';
        file_put_contents($root . '/composer.json', json_encode($composer, JSON_THROW_ON_ERROR | JSON_PRETTY_PRINT) . "\n");
        mkdir($root . '/src');
        self::setQuestTarget($root, self::ENEMY_NAME);
        // Resolving project support classes must work from every caller's cwd.
        file_put_contents($root . '/assets/Data/quests.php', <<<'PHP'
<?php
return [[
    'id' => 'fixture-defeat', 'name' => 'Fixture Quest',
    'description' => 'Exercise reference validation.',
    'objectives' => [['type' => 'defeat', 'target' => \ConsoleFixture\EnemyReference::NAME, 'quantity' => 1]],
]];
PHP);

        return $root;
    }

    /** Replaces only a generated support file, leaving authored project data alone. */
    public static function setQuestTarget(string $root, string $target): void
    {
        file_put_contents($root . '/src/EnemyReference.php', "<?php\nnamespace ConsoleFixture;\n"
            . 'final class EnemyReference { public const string NAME = ' . var_export($target, true) . "; }\n");
    }

    /** @param array<array-key, mixed> $data */
    private static function writeArray(string $path, array $data): void
    {
        if (file_put_contents($path, "<?php\nreturn " . var_export($data, true) . ";\n") === false) {
            throw new RuntimeException('Cannot write integration fixture ' . $path);
        }
    }
}
