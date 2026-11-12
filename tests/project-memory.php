<?php

declare(strict_types=1);

use Ichiloto\Console\Support\GameLaunchCommandBuilder;
use Ichiloto\Console\Support\ProjectMemory;

/**
 * A command that opens a whole project works in the memory a project process
 * has: a lower limit rises to it, and a higher or unlimited one stays.
 */

require dirname(__DIR__) . '/vendor/autoload.php';

$checks = [['128M', ProjectMemory::LIMIT], ['64M', ProjectMemory::LIMIT], ['1G', '1G'], ['-1', '-1'], ['768M', '768M']];
foreach ($checks as [$start, $expected]) {
    ini_set('memory_limit', $start);
    ProjectMemory::raise();
    if (ini_get('memory_limit') !== $expected) {
        fwrite(STDERR, sprintf("memory_limit %s became %s, expected %s\n", $start, ini_get('memory_limit'), $expected));
        exit(1);
    }
}
// The game itself runs at the project limit, in every renderer's launch command.
$builder = new GameLaunchCommandBuilder();
$arguments = $builder->buildGameCommandArguments('/tmp/game.php', '/tmp/error.log');
$limit = array_search('memory_limit=' . ProjectMemory::LIMIT, $arguments, true);
if ($limit === false || ($arguments[$limit - 1] ?? null) !== '-d') {
    fwrite(STDERR, "The game launch arguments omit the project memory limit.\n");
    exit(1);
}
foreach (['terminal', 'gpui'] as $renderer) {
    if (!str_contains($builder->buildGameCommand('/tmp/game.php', '/tmp/error.log', $renderer), "'memory_limit=" . ProjectMemory::LIMIT . "'")) {
        fwrite(STDERR, "The {$renderer} game command omits the project memory limit.\n");
        exit(1);
    }
}
echo "project memory: OK\n";
