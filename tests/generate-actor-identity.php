<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\GenerateActorCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';

$root = sys_get_temp_dir() . '/ichiloto-generated-actor-' . bin2hex(random_bytes(8));
$previousDirectory = getcwd() ?: dirname(__DIR__);
$actorPath = $root . '/assets/Data/Actors/AriaVale.php';

try {
    mkdir($root, 0700, true);
    chdir($root);

    $tester = new CommandTester(new GenerateActorCommand());
    $result = $tester->execute(['name' => 'Aria Vale'], ['interactive' => false]);
    if ($result !== Command::SUCCESS || ! is_file($actorPath)) {
        throw new RuntimeException('generate:actor did not create the actor. ' . $tester->getDisplay());
    }

    $actor = require $actorPath;
    if (($actor['data']['name'] ?? null) !== 'Aria Vale'
        || ($actor['data']['id'] ?? null) !== 'Aria Vale') {
        throw new RuntimeException('generate:actor did not preserve the original display name as the stable id.');
    }

    $before = file_get_contents($actorPath);
    if ($tester->execute(['name' => 'Aria Vale'], ['interactive' => false]) !== Command::FAILURE
        || file_get_contents($actorPath) !== $before) {
        throw new RuntimeException('generate:actor overwrote an existing actor without --force.');
    }
} finally {
    chdir($previousDirectory);
    if (is_file($actorPath)) {
        unlink($actorPath);
    }
    if (is_dir($root . '/assets/Data/Actors')) {
        rmdir($root . '/assets/Data/Actors');
        rmdir($root . '/assets/Data');
        rmdir($root . '/assets');
    }
    if (is_dir($root)) {
        rmdir($root);
    }
}

fwrite(STDOUT, "PASS: generated actors declare stable ids and preserve existing files.\n");
