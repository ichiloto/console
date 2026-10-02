<?php

declare(strict_types=1);

namespace Ichiloto\Console\Battle;

use InvalidArgumentException;

/**
 * One --member of ichiloto battle, as typed: `Actor[:level][,Slot=item...]`,
 * for example `Kaelion:20,Weapon=Iron Sword,Body=Leather Vest`. The actor
 * and items are references (an id or a name) for the project to resolve; a
 * slot given no item is empty.
 */
final readonly class BattleMemberOption
{
  /**
   * @param array<string, ?string> $equipment Item references by slot name, null for an empty slot.
   */
  private function __construct(public string $actor, public ?int $level, public array $equipment) {}

  public static function parse(string $value): self
  {
    $parts = array_map(trim(...), explode(',', $value));
    [$actor, $level] = array_pad(array_map(trim(...), explode(':', array_shift($parts), 2)), 2, null);
    if ($actor === '') {
      throw new InvalidArgumentException(sprintf('--member "%s" needs an actor, as Actor[:level][,Slot=item...].', $value));
    }
    if ($level !== null && preg_match('/\A[1-9][0-9]*\z/', $level) !== 1) {
      throw new InvalidArgumentException(sprintf('--member "%s": the level must be a whole number from 1.', $value));
    }
    $equipment = [];
    foreach ($parts as $part) {
      [$slot, $item] = array_pad(array_map(trim(...), explode('=', $part, 2)), 2, null);
      if ($slot === '' || $item === null) {
        throw new InvalidArgumentException(sprintf('--member "%s": equipment is given as Slot=item, such as Weapon=Iron Sword.', $value));
      }
      $equipment[$slot] = $item === '' ? null : $item;
    }

    return new self($actor, $level === null ? null : (int) $level, $equipment);
  }
}
