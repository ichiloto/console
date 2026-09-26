<?php

return [
  'title' => 'Two Column Cells',
  'startingPositions' => [
    'player' => [
      'destinationMap' => 'village',
      'spawnPoint' => [
        'x' => 1,
        'y' => 1,
      ],
      'spawnSprite' => ['v'],
    ],
  ],
  'battle' => [
    // Battle positions are pixels and stay as they are.
    'formation' => [['x' => 40, 'y' => 10], ['x' => 44, 'y' => 14]],
  ],
];
