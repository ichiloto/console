<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;

/** Explicit tool startup must refuse an older Engine rather than silently show title. */
final readonly class ProjectStartupCheck
{
    public function __construct(private RendererPreparationProcess $process = new RendererPreparationProcess()) {}

    public function requireModeSupport(string $projectDirectory, string $mode): void
    {
        $probe = <<<'PHP'
require $argv[1];
$class = 'Ichiloto\\Engine\\Core\\GameStartupMode';
if (!enum_exists($class) || !is_callable([$class, 'tryFrom']) || $class::tryFrom($argv[2]) === null
    || !defined($class . '::ENVIRONMENT_VARIABLE') || $class::ENVIRONMENT_VARIABLE !== 'ICHILOTO_START_MODE') {
    fwrite(STDERR, 'The project Engine does not support explicit game startup. Update its Engine before using --start.' . PHP_EOL);
    exit(1);
}
echo 'supported';
PHP;
        $result = $this->process->run([PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr',
            '-d', 'log_errors=0', '-r', $probe, $projectDirectory . '/vendor/autoload.php', $mode],
            $projectDirectory, timeoutSeconds: 5);
        if ($result !== 'supported') {
            throw new RuntimeException('The project Engine returned an invalid explicit-startup support result.');
        }
    }
}
