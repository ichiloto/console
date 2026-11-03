<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;

/** Explicit tool startup must refuse an older Engine rather than silently show title. */
final readonly class ProjectStartupCheck
{
    public function __construct(private RendererPreparationProcess $process = new RendererPreparationProcess()) {}

    /** Validate through the project's Engine and prepare its isolated launcher log. */
    public function prepareLaunchContext(string $projectDirectory, string $json): ?string
    {
        new GameLaunchCommandBuilder()->validateLaunchContextTransport($json);
        $probe = <<<'PHP'
require $argv[1];
$class = 'Ichiloto\\Engine\\Core\\GameLaunchContext';
$roots = 'Ichiloto\\Engine\\IO\\LaunchDataRoot';
if (!class_exists($class) || !is_callable([$class, 'fromArray']) || !is_callable([$class, 'consumeEnvironment'])
    || !defined($class . '::ENVIRONMENT_VARIABLE') || $class::ENVIRONMENT_VARIABLE !== 'ICHILOTO_LAUNCH_CONTEXT'
    || !defined($class . '::MAX_TRANSPORT_BYTES') || $class::MAX_TRANSPORT_BYTES !== 65536
    || !is_callable([$roots, 'validate']) || !is_callable([$roots, 'validatePath'])) {
    fwrite(STDERR, 'The project Engine does not support launch contexts. Update its Engine before using --launch-context.' . PHP_EOL);
    exit(1);
}
try {
    $object = json_decode($argv[2], flags: JSON_THROW_ON_ERROR);
    // Preserve JSON object/list distinctions before the Engine's array schema check.
    foreach (['start', 'battleTest'] as $key) {
        if (property_exists($object, $key) && !$object->$key instanceof stdClass) {
            throw new InvalidArgumentException("Launch $key must be a JSON object.");
        }
    }
    $context = $class::fromArray(json_decode($argv[2], true, flags: JSON_THROW_ON_ERROR));
    $errorLogFile = null;
    if ($context->dataRoot !== null) {
        $root = $roots::validate($context->dataRoot, dirname($argv[1], 2));
        $logs = $roots::validatePath($root, $root . DIRECTORY_SEPARATOR . 'logs');
        if (!is_dir($logs) && !@mkdir($logs, 0700)) {
            throw new RuntimeException('Could not create isolated launcher logs.');
        }
        $errorLogFile = $roots::validatePath($root, $logs . DIRECTORY_SEPARATOR . 'error.log');
        $stream = @fopen($errorLogFile, 'ab');
        if (!is_resource($stream)) {
            throw new RuntimeException('Could not open the isolated launcher error log.');
        }
        fclose($stream);
        $roots::validate($root, dirname($argv[1], 2));
        $roots::validatePath($root, $errorLogFile);
    }
    echo json_encode(['errorLogFile' => $errorLogFile], JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    fwrite(STDERR, 'Invalid launch context: ' . $error->getMessage() . PHP_EOL);
    exit(1);
}
PHP;
        $result = $this->process->run([PHP_BINARY, '-d', 'memory_limit=' . ProjectMemory::LIMIT, '-d', 'display_errors=stderr',
            '-d', 'log_errors=0', '-r', $probe, $projectDirectory . '/vendor/autoload.php', $json],
            $projectDirectory, timeoutSeconds: 5);
        $report = json_decode($result, true);
        if (!is_array($report) || array_keys($report) !== ['errorLogFile']
            || ($report['errorLogFile'] !== null && !is_string($report['errorLogFile']))) {
            throw new RuntimeException('The project Engine returned an invalid launch-context support result.');
        }
        return $report['errorLogFile'];
    }

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
        $result = $this->process->run([PHP_BINARY, '-d', 'memory_limit=' . ProjectMemory::LIMIT, '-d', 'display_errors=stderr',
            '-d', 'log_errors=0', '-r', $probe, $projectDirectory . '/vendor/autoload.php', $mode],
            $projectDirectory, timeoutSeconds: 5);
        if ($result !== 'supported') {
            throw new RuntimeException('The project Engine returned an invalid explicit-startup support result.');
        }
    }
}
