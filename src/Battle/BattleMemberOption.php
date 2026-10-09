<?php

declare(strict_types=1);

namespace Ichiloto\Console\Battle;

use InvalidArgumentException;

/**
 * One --member of ichiloto battle, as typed:
 * `Actor[:level][,Slot=item...][,Commands=a|b][,Skills=a|b][,Summons=a|b]`,
 * for example `Kaelion:20,Weapon=Wooden Sword,Commands=attack|magic|summon,Skills=Burn I|Heal I,Summons=djin`.
 *
 * The actor, items, commands, skills and summons are references for the
 * project to resolve. A slot given no item is empty. Commands replaces the
 * member's command menu; Skills and Summons add test-only grants on top of
 * what the member already knows.
 */
final readonly class BattleMemberOption
{
  /** Keys that name a loadout list, never an equipment slot. */
  public const array LOADOUT_KEYS = ['commands', 'skills', 'summons'];

  /**
   * @param array<string, ?string> $equipment Item references by slot name, null for an empty slot.
   * @param list<string>|null $commands Command references replacing the menu, or null to keep the normal commands.
   * @param list<string> $skills Skill references to grant, abilities or spells.
   * @param list<string> $summons Summon references to grant.
   */
  private function __construct(
    public string $actor,
    public ?int $level,
    public array $equipment,
    public ?array $commands = null,
    public array $skills = [],
    public array $summons = [],
  ) {}

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
    $loadout = [];
    foreach ($parts as $part) {
      [$key, $reference] = array_pad(array_map(trim(...), explode('=', $part, 2)), 2, null);
      if ($key === '' || $reference === null) {
        throw new InvalidArgumentException(sprintf(
          '--member "%s": equipment is given as Slot=item, such as Weapon=Iron Sword, and loadouts as Commands=, Skills= or Summons= with | between names.',
          $value,
        ));
      }
      $loadoutKey = strtolower($key);
      if (in_array($loadoutKey, self::LOADOUT_KEYS, true)) {
        if (isset($loadout[$loadoutKey])) {
          throw new InvalidArgumentException(sprintf('--member "%s": %s is given more than once.', $value, $key));
        }
        $loadout[$loadoutKey] = self::parseList($reference, $key, $value);
        continue;
      }
      $equipment[$key] = $reference === '' ? null : $reference;
    }

    return new self(
      $actor,
      $level === null ? null : (int) $level,
      $equipment,
      $loadout['commands'] ?? null,
      $loadout['skills'] ?? [],
      $loadout['summons'] ?? [],
    );
  }

  /** @return list<string> */
  private static function parseList(string $reference, string $key, string $value): array
  {
    $names = array_map(trim(...), explode('|', $reference));
    if (in_array('', $names, true)) {
      throw new InvalidArgumentException(sprintf(
        '--member "%s": %s needs one or more names separated by |, such as %s=%s.',
        $value,
        $key,
        $key,
        match (strtolower($key)) { 'commands' => 'attack|magic|summon', 'skills' => 'Burn I|Heal I', default => 'djin' },
      ));
    }
    $repeated = array_keys(array_filter(array_count_values($names), static fn(int $count): bool => $count > 1));
    if ($repeated !== []) {
      throw new InvalidArgumentException(sprintf('--member "%s": %s names %s more than once.', $value, $key, implode(', ', $repeated)));
    }

    return $names;
  }
}
