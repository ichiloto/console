<?php

declare(strict_types=1);

require dirname(__DIR__, 2) . '/vendor/autoload.php';

(new \Ichiloto\Console\Support\SourceRendererUpdater())->update($argv[1], 'gpui',
    new \Symfony\Component\Console\Output\NullOutput());
