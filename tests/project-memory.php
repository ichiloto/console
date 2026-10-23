<?php

declare(strict_types=1);

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
echo "project memory: OK\n";
