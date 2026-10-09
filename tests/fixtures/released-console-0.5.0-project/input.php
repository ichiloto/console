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