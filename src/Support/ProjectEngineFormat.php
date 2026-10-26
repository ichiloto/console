<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Ichiloto\Engine\Core\ProjectFormat;
use JsonException;
use RuntimeException;

/**
 * Asks the Engine that reads a project which project format it reads. That
 * Engine is the authority on the format a project must be at: the game
 * refuses any other, so every Console command judges a project by it.
 */
final readonly class ProjectEngineFormat
{
    private const string PROBE = <<<'PHP'
require $argv[1];
$class = 'Ichiloto\\Engine\\Core\\ProjectFormat';
echo json_encode(['format' => class_exists($class) && defined($class . '::CURRENT') ? $class::CURRENT : null], JSON_THROW_ON_ERROR);
PHP;

    public function __construct(
        private RendererPreparationProcess $process = new RendererPreparationProcess(),
        private float $timeoutSeconds = 5,
    ) {
    }

    /**
     * The format the project's Engine reads, or null when that Engine
     * predates project formats and reads a project as it is.
     *
     * A project with a Composer install is read by its own Engine, which
     * `play` runs; it answers in its own process so its classes never mix
     * with this Console's. A project without one is read by this Console's
     * Engine, which `validate`, `edit` and `upgrade` load in its place.
     */
    public function getFormat(string $projectRoot): ?int
    {
        $autoload = rtrim($projectRoot, '\\/') . DIRECTORY_SEPARATOR . 'vendor' . DIRECTORY_SEPARATOR . 'autoload.php';

        if (! is_file($autoload)) {
            return class_exists(ProjectFormat::class) ? ProjectFormat::CURRENT : null;
        }

        try {
            $response = $this->process->run([
                PHP_BINARY, '-d', 'memory_limit=' . ProjectMemory::LIMIT, '-d', 'display_errors=stderr', '-d', 'log_errors=0',
                '-r', self::PROBE, $autoload,
            ], $projectRoot, timeoutSeconds: $this->timeoutSeconds);
            $report = json_decode($response, true, 4, JSON_THROW_ON_ERROR);
        } catch (RuntimeException|JsonException $exception) {
            throw new RuntimeException("The project's Engine could not report the project format it reads: " . $exception->getMessage(), previous: $exception);
        }

        if (! is_array($report) || array_keys($report) !== ['format']
            || ! ($report['format'] === null || (is_int($report['format']) && $report['format'] >= 0))) {
            throw new RuntimeException("The project's Engine reported an invalid project format.");
        }

        return $report['format'];
    }
}
