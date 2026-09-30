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