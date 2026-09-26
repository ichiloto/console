<?php

$walk = static fn(array $steps): array => ['type' => 'move_route', 'subject' => 'player', 'steps' => $steps];

return [
  ['type' => 'stage_actor', 'id' => 'crow', 'sprite' => 'v', 'x' => 3, 'y' => 1],
  ['type' => 'camera', 'operation' => 'pan', 'target' => ['kind' => 'position', 'x' => 4, 'y' => 2], 'seconds' => 0.5],
  ['type' => 'field_animation', 'animation' => 'Sparkle', 'target' => ['kind' => 'screen_position', 'x' => 40, 'y' => 5]],
  ['type' => 'move_route', 'subject' => 'npc', 'npcId' => 'elder', 'waypoints' => [['x' => 1], ['x' => 3, 'y' => 3], ['y' => 1]]],
  $walk([['direction' => 'right', 'count' => 2]]),
  [
    'type' => 'branch',
    'conditions' => [['type' => 'switch', 'name' => 'met-elder', 'value' => true]],
    'then' => [['type' => 'transfer', 'map' => 'cave', 'x' => 2, 'y' => 1]],
    'else' => [['type' => 'camera', 'operation' => 'focus', 'kind' => 'position', 'x' => 3, 'y' => 1]],
  ],
];
