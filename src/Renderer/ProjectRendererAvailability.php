<?php

declare(strict_types=1);

namespace Ichiloto\Console\Renderer;

use Ichiloto\Console\Support\RendererPreparationProcess;
use JsonException;
use RuntimeException;
use stdClass;
use UnexpectedValueException;

/** Reads the opened project's Engine contract without loading it into Console. */
final readonly class ProjectRendererAvailability
{
    public const string SCHEMA = 'ichiloto.renderers/1';
    private const int MAX_RENDERERS = 32;
    private const int MAX_ID_BYTES = 64;
    private const int MAX_NAME_BYTES = 128;
    private const int MAX_REASON_BYTES = 2048;

    public function __construct(
        private RendererPreparationProcess $process = new RendererPreparationProcess(),
        private float $timeoutSeconds = 5,
    ) {
    }

    /**
     * @return array{schema: string, renderers: list<array{id: string, displayName: string, available: bool, reason: ?string}>, preferredId: ?string}
     */
    public function getReport(string $projectDirectory): array
    {
        $directory = realpath($projectDirectory);
        if ($directory === false || ! is_dir($directory)) {
            throw new RuntimeException('Supply a valid project directory for renderer discovery.');
        }
        $autoload = $directory . '/vendor/autoload.php';
        if (! is_file($autoload) || ! is_readable($autoload)) {
            throw new RuntimeException('Project dependencies are not installed; run composer install in ' . $directory . '.');
        }

        $probe = <<<'PHP'
try {
    require $argv[1];
    $class = 'Ichiloto\Engine\Rendering\Launch\RendererRegistry';
    if (!class_exists($class)) {
        throw new RuntimeException('The project Engine does not support renderer availability discovery.');
    }
    $registry = new $class();
    if (!is_callable([$registry, 'getReport'])) {
        throw new RuntimeException('The project Engine does not support RendererRegistry::getReport().');
    }
    fwrite(STDOUT, json_encode($registry->getReport(), JSON_THROW_ON_ERROR));
} catch (Throwable $error) {
    fwrite(STDERR, $error->getMessage() . PHP_EOL);
    exit(1);
}
PHP;
        $response = $this->process->run([
            PHP_BINARY, '-d', 'memory_limit=512M', '-d', 'display_errors=stderr', '-d', 'log_errors=0',
            '-r', $probe, $autoload,
        ], $directory, timeoutSeconds: $this->timeoutSeconds);
        try {
            $report = json_decode($response, false, 16, JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new UnexpectedValueException('The project Engine returned malformed renderer availability JSON.', previous: $error);
        }
        if (! $report instanceof stdClass) {
            throw new UnexpectedValueException('The project Engine returned a non-object renderer availability report.');
        }
        $data = get_object_vars($report);
        if (! is_array($data['renderers'] ?? null)) {
            throw new UnexpectedValueException('Renderer availability requires a JSON list of renderers.');
        }
        $data['renderers'] = array_map(static fn(mixed $renderer): mixed => $renderer instanceof stdClass
            ? get_object_vars($renderer) : $renderer, $data['renderers']);
        return self::validateReport($data);
    }

    /**
     * @param array<mixed> $report
     * @return array{schema: string, renderers: list<array{id: string, displayName: string, available: bool, reason: ?string}>, preferredId: ?string}
     */
    public static function validateReport(array $report): array
    {
        if (! self::hasExactKeys($report, ['schema', 'renderers', 'preferredId']) || $report['schema'] !== self::SCHEMA) {
            throw new UnexpectedValueException('Unsupported renderer availability report schema; expected ' . self::SCHEMA . '.');
        }
        if (! is_array($report['renderers']) || ! array_is_list($report['renderers'])
            || count($report['renderers']) > self::MAX_RENDERERS) {
            throw new UnexpectedValueException('Renderer availability requires a list of at most 32 renderers.');
        }
        $statuses = [];
        $renderers = [];
        foreach ($report['renderers'] as $renderer) {
            if (! is_array($renderer) || ! self::hasExactKeys($renderer, ['id', 'displayName', 'available', 'reason'])
                || ! is_string($renderer['id']) || strlen($renderer['id']) > self::MAX_ID_BYTES
                || ! preg_match('/^[a-z][a-z0-9_-]*$/D', $renderer['id'])
                || ! self::isValidText($renderer['displayName'], self::MAX_NAME_BYTES)
                || ! is_bool($renderer['available'])
                || ($renderer['available'] ? $renderer['reason'] !== null
                    : ! self::isValidText($renderer['reason'], self::MAX_REASON_BYTES))) {
                throw new UnexpectedValueException('Malformed renderer availability entry.');
            }
            if (array_key_exists($renderer['id'], $statuses)) {
                throw new UnexpectedValueException('Duplicate renderer availability ID: ' . $renderer['id'] . '.');
            }
            $statuses[$renderer['id']] = $renderer['available'];
            $renderers[] = ['id' => $renderer['id'], 'displayName' => $renderer['displayName'],
                'available' => $renderer['available'], 'reason' => $renderer['reason']];
        }
        $preferredId = $report['preferredId'];
        if (($preferredId === null && in_array(true, $statuses, true))
            || ($preferredId !== null && (! is_string($preferredId) || ($statuses[$preferredId] ?? false) !== true))) {
            throw new UnexpectedValueException('The preferred renderer must identify an available renderer, or be null when none is available.');
        }
        return ['schema' => self::SCHEMA, 'renderers' => $renderers, 'preferredId' => $preferredId];
    }

    /** @param array<mixed> $value @param list<string> $keys */
    private static function hasExactKeys(array $value, array $keys): bool
    {
        return count($value) === count($keys) && array_diff($keys, array_keys($value)) === [];
    }

    private static function isValidText(mixed $value, int $maximumBytes): bool
    {
        return is_string($value) && trim($value) !== '' && strlen($value) <= $maximumBytes
            && ! preg_match('/[\x00-\x1F\x7F]/', $value);
    }
}
