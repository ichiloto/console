<?php

use Ichiloto\Engine\Core\Enumerations\MovementHeading;

return [
  'name' => 'Village',
  // A region map station is a schematic position, not a field cell.
  'station' => ['x' => 3, 'y' => 1],
  'tiles2d' => [
    'asset' => 'Graphics/Tilesets/Village.png',
    'symbols' => [
      '#' => ['x' => 16, 'y' => 0, 'width' => 16, 'height' => 32],
    ],
  ],
  'npcs' => [
    [
      'id' => 'elder',
      'name' => 'Elder',
      'sprite' => '@',
      'x' => 5,
      'y' => 1,
      'wanderArea' => ['x' => 3, 'y' => 1, 'width' => 4, 'height' => 2],
    ],
    [
      'id' => 'guard',
      'name' => 'Guard',
      'sprite' => '@',
      'x' => 9,
      'y' => 4,
    ],
    [
      'id' => 'twin',
      'name' => 'Twin',
      'sprite' => '@',
      'x' => 4,
      'y' => 1,
    ],
    ...array_map(
      static fn(int $x): array => ['id' => "post-{$x}", 'name' => 'Post', 'sprite' => '|', 'x' => $x, 'y' => 4],
      [6],
    ),
  ],
  'triggers' => [
    [
      'destinationMap' => 'grove',
      'trigger_area' => ['x' => 7, 'y' => 4, 'width' => 1, 'height' => 1],
      'spawn_point' => ['x' => 3, 'y' => 1],
    ],
  ],
  'events' => [
    'A' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\TransferPlayerTrigger',
      'data' => [
        'destinationMap' => 'cave',
        'spawnPoint' => [
          'x' => 2,
          'y' => 3,
        ],
        'spawnSprite' => [MovementHeading::SOUTH->value],
      ],
    ],
    'B' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ChestEventTrigger',
      'data' => ['loot' => 'Potion', 'quantity' => 1],
    ],
    'C' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ChestEventTrigger',
      'data' => ['loot' => 'Ether', 'quantity' => 1],
    ],
    'E' => [
      'class' => 'Ichiloto\\Engine\\Events\\Triggers\\ScriptEventTrigger',
      'data' => [
        'mode' => 'action',
        'script' => [
          ['type' => 'move_player', 'x' => 7, 'y' => 1],
          ['type' => 'move_route', 'subject' => 'player', 'steps' => [
            ['direction' => 'left', 'count' => 3],
            ['direction' => 'up'],
            ['direction' => 'right'],
            ['direction' => 'left', 'faceOnly' => true],
          ]],
          ['type' => 'transfer', 'map' => 'village', 'x' => 5, 'y' => 1],
        ],
      ],
    ],
  ],
  'decorations' => [
    'banner' => ['x' => 12, 'y' => 0],
  ],
];
