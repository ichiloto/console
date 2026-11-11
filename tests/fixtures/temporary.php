<?php
declare(strict_types=1);
require_once __DIR__ . '/TemporaryFixtures.php';

/** Each CLI probe owns its process namespace, lock and shutdown recovery. */
function getConsoleTestTemporaryDirectory(): string
{
    return \Ichiloto\Console\Tests\Support\TemporaryFixtures::getDirectory();
}
