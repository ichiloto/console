<?php

declare(strict_types=1);

/**
 * Checks how a --member's loadout is read: Commands replaces the member's
 * command menu, Skills and Summons add test grants, each a |-separated list,
 * and the three keys never become equipment slots.
 */

use Ichiloto\Console\Battle\BattleMemberOption;

require dirname(__DIR__) . '/vendor/autoload.php';

function assertLoadout(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "FAIL: {$message}\n");
        exit(1);
    }
}

function loadoutFailure(string $value): string
{
    try {
        BattleMemberOption::parse($value);
    } catch (InvalidArgumentException $invalid) {
        return $invalid->getMessage();
    }

    return '';
}

$plain = BattleMemberOption::parse('Kaelion:20,Weapon=Iron Sword');
assertLoadout($plain->commands === null && $plain->skills === [] && $plain->summons === []
    && $plain->equipment === ['Weapon' => 'Iron Sword'],
    'a member without a loadout keeps its normal commands and gains nothing');

$liora = BattleMemberOption::parse('Liora:20, Weapon=Wooden Staff, Commands=attack|magic|summon|item, Skills=Burn I | Heal I, summons=ifrit');
assertLoadout($liora->actor === 'Liora' && $liora->level === 20
    && $liora->equipment === ['Weapon' => 'Wooden Staff']
    && $liora->commands === ['attack', 'magic', 'summon', 'item']
    && $liora->skills === ['Burn I', 'Heal I']
    && $liora->summons === ['ifrit'],
    'Commands, Skills and Summons are read as lists, in any case, and are not equipment slots');

assertLoadout(str_contains(loadoutFailure('Liora,Commands='), 'Commands needs one or more names separated by |'),
    'an empty list is refused, not read as no commands');
assertLoadout(str_contains(loadoutFailure('Liora,Skills=Burn I||Heal I'), 'Skills needs one or more names'),
    'an empty name in a list is refused');
assertLoadout(str_contains(loadoutFailure('Liora,Skills=Burn I|Burn I'), 'Skills names Burn I more than once'),
    'a repeated name is refused');
assertLoadout(str_contains(loadoutFailure('Liora,Summons=ifrit,Summons=torro'), 'Summons is given more than once'),
    'a loadout key given twice is refused');
assertLoadout(str_contains(loadoutFailure('Liora,Commands'), 'loadouts as Commands=, Skills= or Summons='),
    'a key without = says how loadouts are written');

fwrite(STDOUT, "PASS: --member reads commands, skills and summons as test loadouts.\n");
