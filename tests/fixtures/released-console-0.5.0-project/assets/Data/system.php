<?php

return [
  'title' => 'Released 0.5 Project',
  'currency' => [
    'amount' => 0,
  ],
  'startingParty' => [
    'AriaVale',
  ],
  'startingInventory' => [],
  'startingPositions' => [
    'player' => [
      'destinationMap' => 'campfire-clearing',
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
    'engine' => 'traditional',
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
];
