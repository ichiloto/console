<?php

use Ichiloto\Engine\Events\Enumerations\CollisionType;

return [
  '#' => CollisionType::SOLID,
  '|' => CollisionType::SOLID,
  '~' => CollisionType::SOLID,
  '.' => CollisionType::NONE,
  ' ' => CollisionType::NONE,
  'o' => CollisionType::COLLECTABLE,
];
