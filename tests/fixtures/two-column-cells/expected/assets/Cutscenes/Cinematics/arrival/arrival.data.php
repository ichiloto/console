<?php

return [
  'id' => 'arrival',
  'name' => 'Arrival',
  'startMap' => 'village',
  'cast' => [[
    'id' => 'gate',
    'sprite' => ['=='],
    'x' => 3,
    'y' => 3,
    'collision' => false,
  ]],
  'skip' => ['policy' => 'authored'],
  'checkpoints' => [],
  'finalizer' => [
    ['type' => 'transfer', 'map' => 'village', 'x' => 0, 'y' => 1],
    ['type' => 'camera', 'operation' => 'attach'],
  ],
];
