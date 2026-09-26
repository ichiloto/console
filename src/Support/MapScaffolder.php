<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Ichiloto\Engine\Field\MapCell;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Writes the three files that make up a map: its data, one terrain layer of
 * two-column cells, and an event layer of blank cells matching it.
 */
final class MapScaffolder
{
    /**
     * @param array<string, mixed> $data
     * @param string[]|null $tileRows
     * @return array{data: string, map: string, event: string}
     */
    public function write(string $directory, array $data, ?array $tileRows = null, bool $force = false): array
    {
        $directory = rtrim($directory, '/\\');
        $leaf = basename($directory);

        if ($leaf === '' || $leaf === '.' || $leaf === '..') {
            throw new RuntimeException('The map directory must have a valid map id.');
        }

        $paths = [
            'data' => $directory . DIRECTORY_SEPARATOR . $leaf . '.data.php',
            'map' => $directory . DIRECTORY_SEPARATOR . $leaf . '.map.php',
            'event' => $directory . DIRECTORY_SEPARATOR . $leaf . '.event.php',
        ];

        if (! $force) {
            foreach ($paths as $path) {
                if (file_exists($path)) {
                    throw new RuntimeException("Map file already exists: {$path} (use --force to overwrite)");
                }
            }
        }

        $tileRows ??= self::defaultTileRows();
        $eventRows = [];

        foreach ($tileRows as $index => $row) {
            try {
                $eventRows[] = implode('', MapCell::getBlankRow(count(MapCell::parseRow($row, "Map row {$index}"))));
            } catch (InvalidArgumentException $error) {
                throw new RuntimeException($error->getMessage(), previous: $error);
            }
        }

        if (! is_dir($directory) && ! mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException("Could not create directory: {$directory}");
        }

        $payloads = [
            'data' => "<?php\n\nreturn " . var_export($data, true) . ";\n",
            'map' => self::renderLayer('ICHILOTO_MAP', $tileRows),
            'event' => self::renderLayer('ICHILOTO_EVENT_MAP', $eventRows),
        ];
        $temporaryPaths = [];

        try {
            foreach ($paths as $type => $path) {
                $temporaryPath = $path . '.tmp.' . bin2hex(random_bytes(6));
                $temporaryPaths[$type] = $temporaryPath;

                if (file_put_contents($temporaryPath, $payloads[$type]) === false) {
                    throw new RuntimeException("Could not write map file: {$path}");
                }
            }

            foreach ($paths as $type => $path) {
                if (! rename($temporaryPaths[$type], $path)) {
                    throw new RuntimeException("Could not publish map file: {$path}");
                }

                unset($temporaryPaths[$type]);
            }
        } catch (Throwable $throwable) {
            foreach ($temporaryPaths as $temporaryPath) {
                if (is_file($temporaryPath)) {
                    unlink($temporaryPath);
                }
            }

            throw new RuntimeException($throwable->getMessage(), previous: $throwable);
        }

        return $paths;
    }

    /** @return string[] */
    private static function defaultTileRows(): array
    {
        $cells = 24;
        $border = str_repeat('##', $cells);

        return [
            $border,
            ...array_fill(0, 16, '##' . str_repeat(MapCell::BLANK, $cells - 2) . '##'),
            $border,
        ];
    }

    /** @param string[] $rows */
    private static function renderLayer(string $label, array $rows): string
    {
        return sprintf(
            "<?php\n\nreturn <<<'%s'\n%s\n%s;\n",
            $label,
            implode(PHP_EOL, $rows),
            $label,
        );
    }
}
