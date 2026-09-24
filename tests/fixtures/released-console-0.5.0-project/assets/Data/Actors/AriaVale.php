<?php

use Ichiloto\Engine\Entities\Character;

return [
  'class' => Character::class,
  'data' => [
    'name' => 'Aria Vale',
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