<?php

return [
  ['type' => 'camera', 'operation' => 'route', 'points' => [
    ['target' => ['kind' => 'position', 'x' => 2, 'y' => 1], 'seconds' => 0.5],
    ['kind' => 'position', 'x' => 8, 'y' => 3, 'seconds' => 0.5],
  ]],
  ['type' => 'move_route', 'subject' => 'player', 'waypoints' => [['x' => 5], ['y' => 3]]],
  ['type' => 'move_route', 'subject' => 'player', 'steps' => [['direction' => 'right', 'count' => 2 + 1]]],
];
