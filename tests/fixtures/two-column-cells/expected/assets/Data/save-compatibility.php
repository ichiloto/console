<?php

use Ichiloto\Engine\IO\SaveCompatibility\TwoColumnCellsMigration;

return [
  'contentVersion' => 3,
  'migrations' => [
    [
      'from' => 2,
      'to' => 3,
      'class' => TwoColumnCellsMigration::class,
    ],
  ],
  'aliases' => [
    'maps' => [
      ['from' => 'old-village', 'to' => 'village'],
    ],
  ],
  'tombstones' => [],
];
