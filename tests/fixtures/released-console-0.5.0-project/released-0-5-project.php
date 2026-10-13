<?php

declare(strict_types=1);

use Ichiloto\Engine\Core\Game;

$autoloadPath = __DIR__ . '/vendor/autoload.php';

if (! file_exists($autoloadPath)) {
    fwrite(STDERR, "Project dependencies are missing. Run `composer install` in this directory before playing.\n");
    exit(1);
}

require $autoloadPath;

$game = new Game('Released 0.5 Project');
$game->run();