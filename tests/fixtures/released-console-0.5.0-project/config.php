<?php

return [
  'vocab' => [
    'game' => [
      'new_game' => 'New Game',
      'continue' => 'Load Game',
      'options' => 'Options',
      'save' => 'Save',
      'load' => 'Load',
      'shutdown' => 'Exit',
      'to_title' => 'To Title',
    ],
    'shop' => [
      'buy' => 'Buy',
      'sell' => 'Sell',
      'cancel' => 'Cancel',
      'possession' => 'Possession',
      'exp_total' => 'Current Exp',
    ],
    'battle' => [],
    'command' => [
      'attack' => 'Attack',
      'skill' => 'Skill',
      'guard' => 'Guard',
      'item' => 'Item',
      'equip' => 'Equip',
      'status' => 'Status',
      'escape' => 'Escape',
      'new_game' => 'New Game',
      'continue' => 'Continue',
      'options' => 'Options',
      'game_end' => 'Quit',
    ],
    'currency' => [
      'name' => 'Gold',
      'symbol' => 'G',
    ],
  ],
  'messages' => [
    'exp_total' => 'Current %1',
    'exp_next' => 'To Next %1',
    'party_name' => '%1\'s Party',
    'obtained_exp' => '%1 %2 obtained!',
    'obtained_gold' => '%1 %2 found!',
    'obtained_item' => '%1 found!',
    'file' => 'File',
    'prompt' => [
      'save' => 'Save to which file?',
      'load' => 'Load from which file?',
    ],
    'confirm' => [
      'quit' => 'Are you sure you want to quit?',
    ],
    'inventory' => [
      'empty' => 'No items.',
      'quantity' => 'x%1',
      'possession' => 'Possession',
      'gold' => 'Gold',
      'equip' => 'Equip',
      'use' => 'Use',
      'discard' => 'Discard',
      'cancel' => 'Cancel',
    ],
  ],
  'ui' => [
    'cursor' => [
      'memory' => true,
    ],
    'dialogue' => [
      'speed' => 20,
      'window' => [
        'position' => \Ichiloto\Engine\UI\Windows\Enumerations\WindowPosition::TOP,
      ],
      'message' => [
        'speed' => 20,
      ],
    ],
    'battle' => [
      'message_pace' => 'slow',
      'animation_pace' => 'slow',
      'selection_color' => \Ichiloto\Engine\IO\Enumerations\Color::LIGHT_CYAN,
    ],
    'menu' => [
      'selection_color' => \Ichiloto\Engine\IO\Enumerations\Color::LIGHT_CYAN,
    ],
    'hud' => [
      'location' => true,
    ],
    'notifications' => [
      'duration' => 3000,
    ],
  ],
  'audio' => [
    'master_volume' => 70,
    'music' => true,
    'sfx' => true,
  ],
  'inn' => [
    'base_cost' => 30,
    'sleep_time' => 3,
  ],
];