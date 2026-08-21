<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Ichiloto\Console\Util\Path;
use RuntimeException;

final class NewProjectScaffolder
{
    private const string STARTING_MAP_ID = 'campfire-clearing';

    /**
     * @param array{
     *   displayName: string,
     *   directoryName: string,
     *   targetDirectory: string,
     *   heroName: string,
     *   heroId: string,
     *   battleEngine: string,
     *   titleArt: string
     * } $blueprint
     * @return array{files: string[], directories: string[]}
     */
    public function scaffold(array $blueprint): array
    {
        $directories = $this->createDirectories($blueprint['targetDirectory']);
        $files = [];

        $mainFilename = $blueprint['directoryName'] . '.php';
        $projectId = 'ichiloto/' . $blueprint['directoryName'];
        $heroPath = Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'Actors', $blueprint['heroId'] . '.php');
        $mapDirectory = Path::join($blueprint['targetDirectory'], 'assets', 'Maps', self::STARTING_MAP_ID);

        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'ichiloto.json'),
            $this->renderIchilotoConfig($projectId, $blueprint['displayName'], $mainFilename),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'composer.json'),
            $this->renderComposerJson($projectId, $mainFilename),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'config.php'),
            $this->renderProjectConfig(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'input.php'),
            $this->renderInputConfig(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], $mainFilename),
            $this->renderMainEntrypoint($blueprint['displayName']),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], '.gitignore'),
            $this->renderGitIgnore(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'abilities.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'animations.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'classes.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'enemies.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'items.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'magic.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'skills.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'save-compatibility.php'),
            SaveCompatibilityMetadata::renderBaseline(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'system.php'),
            $this->renderSystemData(
                title: $blueprint['displayName'],
                heroId: $blueprint['heroId'],
                battleEngine: $blueprint['battleEngine'],
            ),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'troops.php'),
            $this->renderPhpArrayFile([]),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Data', 'Entities', 'player.php'),
            $this->renderPlayerEntity(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Graphics', 'System', 'title.txt'),
            $blueprint['titleArt'],
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Graphics', 'System', 'game-over.txt'),
            $this->renderGameOverGraphic(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Graphics', 'Animations', 'battle-transition.txt'),
            $this->renderBattleTransitionGraphic(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'assets', 'Maps', 'collisions.php'),
            $this->renderCollisionDictionary(),
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], 'logs', '.gitkeep'),
            '',
        );
        $files[] = $this->writeFile(
            Path::join($blueprint['targetDirectory'], '.data', 'saves', '.gitkeep'),
            '',
        );

        $files[] = $this->writeFile(
            $heroPath,
            $this->renderActorData($blueprint['heroName']),
        );

        $this->writeStarterMap($mapDirectory);
        $files[] = Path::join($mapDirectory, self::STARTING_MAP_ID . '.data.php');
        $files[] = Path::join($mapDirectory, self::STARTING_MAP_ID . '.map.php');
        $files[] = Path::join($mapDirectory, self::STARTING_MAP_ID . '.event.php');

        return [
            'files' => $files,
            'directories' => $directories,
        ];
    }

    public function ensureTargetIsAvailable(string $targetDirectory): void
    {
        if (file_exists($targetDirectory) && ! is_dir($targetDirectory)) {
            throw new RuntimeException(sprintf('%s already exists and is not a directory.', $targetDirectory));
        }

        if (! is_dir($targetDirectory)) {
            return;
        }

        $contents = array_values(array_diff(scandir($targetDirectory) ?: [], ['.', '..']));

        if ($contents !== []) {
            throw new RuntimeException(sprintf('%s already exists and is not empty.', $targetDirectory));
        }
    }

    /**
     * @return string[]
     */
    private function createDirectories(string $targetDirectory): array
    {
        $directories = [
            $targetDirectory,
            Path::join($targetDirectory, 'assets'),
            Path::join($targetDirectory, 'assets', 'Data'),
            Path::join($targetDirectory, 'assets', 'Data', 'Actors'),
            Path::join($targetDirectory, 'assets', 'Data', 'Entities'),
            Path::join($targetDirectory, 'assets', 'Graphics'),
            Path::join($targetDirectory, 'assets', 'Graphics', 'Animations'),
            Path::join($targetDirectory, 'assets', 'Graphics', 'System'),
            Path::join($targetDirectory, 'assets', 'Maps'),
            Path::join($targetDirectory, 'assets', 'Maps', self::STARTING_MAP_ID),
            Path::join($targetDirectory, 'logs'),
            Path::join($targetDirectory, '.data'),
            Path::join($targetDirectory, '.data', 'saves'),
        ];

        foreach ($directories as $directory) {
            if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
                throw new RuntimeException(sprintf('Unable to create %s.', $directory));
            }
        }

        return $directories;
    }

    private function writeStarterMap(string $mapDirectory): void
    {
        $mapData = [
            'name' => 'Campfire Clearing',
            'region' => 'Prologue',
            'description' => 'A quiet clearing where new legends are first etched into the dark.',
            'triggers' => [],
            'events' => [],
        ];

        $tileRows = [
            '################################################',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                       ?                      #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '#                                              #',
            '################################################',
        ];
        $eventRows = array_fill(0, count($tileRows), str_repeat(' ', strlen($tileRows[0])));

        $this->writeFile(
            Path::join($mapDirectory, self::STARTING_MAP_ID . '.data.php'),
            $this->renderPhpArrayFile($mapData),
        );
        $this->writeFile(
            Path::join($mapDirectory, self::STARTING_MAP_ID . '.map.php'),
            $this->renderMapLayer('ICHILOTO_MAP', $tileRows),
        );
        $this->writeFile(
            Path::join($mapDirectory, self::STARTING_MAP_ID . '.event.php'),
            $this->renderMapLayer('ICHILOTO_EVENT_MAP', $eventRows),
        );
    }

    private function writeFile(string $path, string $contents): string
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create %s.', $directory));
        }

        $bytes = file_put_contents($path, $contents);

        if ($bytes === false) {
            throw new RuntimeException(sprintf('Unable to write %s.', $path));
        }

        return $path;
    }

    private function renderIchilotoConfig(string $projectId, string $displayName, string $mainFilename): string
    {
        return json_encode([
            'id' => $projectId,
            'name' => $displayName,
            'description' => 'A terminal-born RPG forged with the Ichiloto Engine.',
            'version' => '0.1.0',
            'author' => '',
            'main' => $mainFilename,
            'paths' => [
                'assets' => 'assets/',
            ],
            'debug' => [
                'enabled' => false,
                'level' => 2,
                'show' => false,
                'skip_splash' => true,
            ],
            'splash_screen' => [
                'enabled' => false,
                'filename' => '',
                'duration' => 2,
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function renderComposerJson(string $projectId, string $mainFilename): string
    {
        return json_encode([
            'name' => $projectId,
            'description' => 'A terminal-native RPG created with the Ichiloto Engine.',
            'type' => 'project',
            'require' => [
                'php' => '^8.4',
                'ichiloto/engine' => '^0.5',
            ],
            'scripts' => [
                'play' => sprintf('php %s', $mainFilename),
            ],
        ], JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL;
    }

    private function renderProjectConfig(): string
    {
        return <<<'PHP'
<?php

return [
  'vocab' => [
    'game' => [
      'new_game' => 'New Game',
      'continue' => 'Load Game',
      'options' => 'Options',
      'save' => 'Save',
      'load' => 'Load',
      'shutdown' => 'Exit',
      'to_title' => 'To Title',
    ],
    'shop' => [
      'buy' => 'Buy',
      'sell' => 'Sell',
      'cancel' => 'Cancel',
      'possession' => 'Possession',
      'exp_total' => 'Current Exp',
    ],
    'battle' => [],
    'command' => [
      'attack' => 'Attack',
      'skill' => 'Skill',
      'guard' => 'Guard',
      'item' => 'Item',
      'equip' => 'Equip',
      'status' => 'Status',
      'escape' => 'Escape',
      'new_game' => 'New Game',
      'continue' => 'Continue',
      'options' => 'Options',
      'game_end' => 'Quit',
    ],
    'currency' => [
      'name' => 'Gold',
      'symbol' => 'G',
    ],
  ],
  'messages' => [
    'exp_total' => 'Current %1',
    'exp_next' => 'To Next %1',
    'party_name' => '%1\'s Party',
    'obtained_exp' => '%1 %2 obtained!',
    'obtained_gold' => '%1 %2 found!',
    'obtained_item' => '%1 found!',
    'file' => 'File',
    'prompt' => [
      'save' => 'Save to which file?',
      'load' => 'Load from which file?',
    ],
    'confirm' => [
      'quit' => 'Are you sure you want to quit?',
    ],
    'inventory' => [
      'empty' => 'No items.',
      'quantity' => 'x%1',
      'possession' => 'Possession',
      'gold' => 'Gold',
      'equip' => 'Equip',
      'use' => 'Use',
      'discard' => 'Discard',
      'cancel' => 'Cancel',
    ],
  ],
  'ui' => [
    'cursor' => [
      'memory' => true,
    ],
    'dialogue' => [
      'speed' => 20,
      'window' => [
        'position' => \Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition::TOP,
      ],
      'message' => [
        'speed' => 20,
      ],
    ],
    'battle' => [
      'message_pace' => 'slow',
      'animation_pace' => 'slow',
      'selection_color' => \Ichiloto\Engine\IO\Enumerations\Color::LIGHT_CYAN,
    ],
    'menu' => [
      'selection_color' => \Ichiloto\Engine\IO\Enumerations\Color::LIGHT_CYAN,
    ],
    'hud' => [
      'location' => true,
    ],
    'notifications' => [
      'duration' => 3000,
    ],
  ],
  'audio' => [
    'master_volume' => 70,
    'music' => true,
    'sfx' => true,
  ],
  'inn' => [
    'base_cost' => 30,
    'sleep_time' => 3,
  ],
];
PHP;
    }

    private function renderInputConfig(): string
    {
        return <<<'PHP'
<?php

use Ichiloto\Engine\IO\Enumerations\KeyCode;

return [
  'action' => [
    'description' => 'Perform an action.',
    'keys' => [KeyCode::SPACE, KeyCode::ENTER],
  ],
  'cancel' => [
    'description' => 'Cancel the current action.',
    'keys' => [KeyCode::C, KeyCode::c, KeyCode::ESCAPE],
  ],
  'back' => [
    'description' => 'Go back.',
    'keys' => [KeyCode::ESCAPE],
  ],
  'confirm' => [
    'description' => 'Confirm the current action.',
    'keys' => [KeyCode::ENTER],
  ],
  'character_next' => [
    'description' => 'Cycle to the next character.',
    'keys' => [KeyCode::TAB],
  ],
  'character_previous' => [
    'description' => 'Cycle to the previous character.',
    'keys' => [KeyCode::SHIFT_TAB],
  ],
  'quit' => [
    'description' => 'Quit the game.',
    'keys' => [KeyCode::Q, KeyCode::q],
  ],
  'up' => [
    'description' => 'Move up.',
    'keys' => [KeyCode::UP, KeyCode::W, KeyCode::w],
  ],
  'down' => [
    'description' => 'Move down.',
    'keys' => [KeyCode::DOWN, KeyCode::S, KeyCode::s],
  ],
  'left' => [
    'description' => 'Move left.',
    'keys' => [KeyCode::LEFT, KeyCode::A, KeyCode::a],
  ],
  'right' => [
    'description' => 'Move right.',
    'keys' => [KeyCode::RIGHT, KeyCode::D, KeyCode::d],
  ],
  'menu' => [
    'description' => 'Open the in-game menu.',
    'keys' => [KeyCode::ESCAPE],
  ],
  'map' => [
    'description' => 'Open the map.',
    'keys' => [KeyCode::M, KeyCode::m],
  ],
  'skit' => [
    'description' => 'Play an available skit.',
    'keys' => [KeyCode::T, KeyCode::t],
  ],
  'pause' => [
    'description' => 'Pause the game.',
    'keys' => [KeyCode::ESCAPE],
  ],
];
PHP;
    }

    private function renderMainEntrypoint(string $displayName): string
    {
        return sprintf(
            <<<'PHP'
<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;

$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (! file_exists($autoloadPath)) {
    fwrite(STDERR, "Project dependencies are missing. Run `composer install` in this directory before playing.\n");
    exit(1);
}

require $autoloadPath;

$game = new Game(%s);
$game->run();
PHP,
            var_export($displayName, true),
        );
    }

    private function renderGitIgnore(): string
    {
        return <<<'TXT'
/vendor/
/.idea/
/.vscode/
/.env
*.log
logs/*
!logs/.gitkeep
.data/saves/*
!.data/saves/.gitkeep
*.iedata
TXT;
    }

    private function renderSystemData(string $title, string $heroId, string $battleEngine): string
    {
        return $this->renderPhpArrayFile([
            'title' => $title,
            'currency' => [
                'amount' => 0,
            ],
            'startingParty' => [
                $heroId,
            ],
            'startingInventory' => [],
            'startingPositions' => [
                'player' => [
                    'destinationMap' => self::STARTING_MAP_ID,
                    'spawnPoint' => [
                        'x' => 4,
                        'y' => 4,
                    ],
                    'spawnSprite' => [
                        'v',
                    ],
                ],
            ],
            'battle' => [
                'engine' => $battleEngine,
                'activeTime' => [
                    'mode' => 'wait',
                    'baseFillRate' => 35,
                    'speedFactorPercent' => 100,
                    'openingVariance' => 24,
                    'openingSpeedFactorPercent' => 250,
                    'surpriseAttackChancePercent' => 8,
                    'backAttackChancePercent' => 6,
                ],
            ],
        ]);
    }

    private function renderPlayerEntity(): string
    {
        return <<<'PHP'
<?php

return [
  'sprites' => [
    'north' => ['^'],
    'east' => ['>'],
    'south' => ['v'],
    'west' => ['<'],
  ],
];
PHP;
    }

    private function renderGameOverGraphic(): string
    {
        return <<<'TXT'
================
   GAME OVER
================
TXT . PHP_EOL;
    }

    private function renderBattleTransitionGraphic(): string
    {
        return <<<'TXT'
****************
*   BATTLE!    *
****************
TXT . PHP_EOL;
    }

    private function renderActorData(string $heroName): string
    {
        return str_replace('HERO_NAME', var_export($heroName, true), <<<'PHP'
<?php

use Ichiloto\Engine\Entities\Character;

return [
  'class' => Character::class,
  'data' => [
    'name' => HERO_NAME,
    'description' => '',
    'level' => 1,
    'currentExp' => 0,
    'stats' => [
      'currentHp' => 100,
      'currentMp' => 20,
      'currentAp' => 10,
      'totalHp' => 100,
      'totalMp' => 20,
      'totalAp' => 10,
      'attack' => 10,
      'defence' => 10,
      'magicAttack' => 10,
      'magicDefence' => 10,
      'grace' => 10,
      'speed' => 10,
      'evasion' => 5,
      'accuracy' => 5,
      'critical' => 5,
    ],
    'images' => [
      'dialog' => [],
      'field' => [],
      'battle' => [],
    ],
    'abilities' => [
      'learned' => [],
      'learnables' => [],
      'sortOrder' => 'A-Z',
    ],
    'magic' => [
      'learned' => [],
      'learnables' => [],
      'sortOrder' => 'A-Z',
    ],
  ],
];
PHP
        );
    }

    private function renderCollisionDictionary(): string
    {
        return <<<'PHP'
<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;

return [
  ';' => CollisionType::ENCOUNTER,
  '~' => CollisionType::SOLID,
  '|' => CollisionType::SOLID,
  '-' => CollisionType::SOLID,
  '(' => CollisionType::SOLID,
  ')' => CollisionType::SOLID,
  'x' => CollisionType::SOLID,
  '.' => CollisionType::SOLID,
  '`' => CollisionType::SOLID,
  '#' => CollisionType::SOLID,
  ':' => CollisionType::SOLID,
  '?' => CollisionType::SAVE_POINT,
  'o' => CollisionType::COLLECTABLE,
  ' ' => CollisionType::NONE,
  '@' => CollisionType::NPC,
];
PHP;
    }

    /**
     * @param array<string, mixed>|list<mixed> $payload
     */
    private function renderPhpArrayFile(array $payload): string
    {
        return "<?php\n\nreturn " . $this->exportPhpValue($payload) . ";\n";
    }

    /**
     * @param string[] $rows
     */
    private function renderMapLayer(string $heredocLabel, array $rows): string
    {
        return sprintf(
            "<?php\n\nreturn <<<'%s'\n%s\n%s;\n",
            $heredocLabel,
            implode(PHP_EOL, $rows),
            $heredocLabel,
        );
    }

    private function exportPhpValue(mixed $value, int $indentLevel = 0): string
    {
        if (! is_array($value)) {
            return var_export($value, true);
        }

        if ($value === []) {
            return '[]';
        }

        $indent = str_repeat('  ', $indentLevel);
        $nextIndent = str_repeat('  ', $indentLevel + 1);
        $lines = ['['];
        $isList = array_is_list($value);

        foreach ($value as $key => $item) {
            $exportedItem = $this->exportPhpValue($item, $indentLevel + 1);

            if ($isList) {
                $lines[] = sprintf('%s%s,', $nextIndent, $exportedItem);
                continue;
            }

            $lines[] = sprintf('%s%s => %s,', $nextIndent, var_export($key, true), $exportedItem);
        }

        $lines[] = sprintf('%s]', $indent);

        return implode(PHP_EOL, $lines);
    }
}
