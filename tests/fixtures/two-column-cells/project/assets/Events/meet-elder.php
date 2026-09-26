<?php

$walk = static fn(array $steps): array => ['type' => 'move_route', 'subject' => 'player', 'steps' => $steps];

return [
  ['type' => 'stage_actor', 'id' => 'crow', 'sprite' => 'v', 'x' => 7, 'y' => 1],
  ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 9, 'y' => 2], 'seconds' => 0.5],
  ['type' => 'field_animation', 'animation' => 'Sparkle', 'target' => ['kind' => 'screen_position', 'x' => 40, 'y' => 5]],
  ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'elder', 'waypoints' => [['x' => 3], ['x' => 7, 'y' => 3], ['y' => 1]]],
  $walk([['direction' => 'right', 'count' => 4]]),
  [
    'type' => 'branch',
    'conditions' => [['type' => 'switch', 'name' => 'met-elder', 'value' => true]],
    'then' => [['type' => 'transfer', 'map' => 'cave', 'x' => 5, 'y' => 1]],
    'else' => [['type' => 'camera', 'operation' => 'focus', 'kind' => 'position', 'x' => 6, 'y' => 1]],
  ],
];
