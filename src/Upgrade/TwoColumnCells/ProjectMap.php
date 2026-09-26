<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Engine\Field\MapLayerSource;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;

/**
 * One map's files, found the way the engine resolves them: a map id is its
 * directory under assets/Maps, holding `<leaf>.data.php`, `<leaf>.event.php`
 * and either a `layers/` directory or a legacy `<leaf>.map.php`.
 */
final readonly class ProjectMap
{
    public const string MAPS_DIRECTORY = 'assets/Maps';

    /**
     * @param string $id The map id, such as `happyville/inn/front`.
     * @param string $dataPath The map data file.
     * @param string|null $eventPath The event layer, when it exists.
     * @param array<string, array{name: string, order: int, decoration: bool}> $layerPaths Layer files and their identities, in load order.
     * @param bool $legacy Whether the map has one `<leaf>.map.php` instead of `layers/`.
     */
    public function __construct(
        public string $id,
        public string $dataPath,
        public ?string $eventPath,
        public array $layerPaths,
        public bool $legacy,
    ) {
    }

    /**
     * Every map in a project, by id.
     *
     * @return array<string, self>
     */
    public static function findAll(string $projectRoot): array
    {
        $mapsRoot = $projectRoot . DIRECTORY_SEPARATOR . str_replace('/', DIRECTORY_SEPARATOR, self::MAPS_DIRECTORY);

        if (! is_dir($mapsRoot)) {
            return [];
        }

        $maps = [];
        $files = new RecursiveIteratorIterator(new RecursiveDirectoryIterator($mapsRoot, RecursiveDirectoryIterator::SKIP_DOTS));

        foreach ($files as $file) {
            $path = $file->getPathname();
            $directory = dirname($path);
            $leaf = basename($directory);

            if (basename($path) !== $leaf . '.data.php') {
                continue;
            }

            $id = str_replace(DIRECTORY_SEPARATOR, '/', substr($directory, strlen($mapsRoot) + 1));
            $eventPath = $directory . DIRECTORY_SEPARATOR . $leaf . '.event.php';
            $layerDirectory = $directory . DIRECTORY_SEPARATOR . MapLayerSource::DIRECTORY;
            $layerPaths = [];
            $legacy = ! is_dir($layerDirectory);

            if ($legacy) {
                $legacyPath = $directory . DIRECTORY_SEPARATOR . $leaf . '.map.php';
                if (is_file($legacyPath)) {
                    $layerPaths[$legacyPath] = ['name' => 'terrain', 'order' => 0, 'decoration' => false];
                }
            } else {
                $layerFiles = glob($layerDirectory . DIRECTORY_SEPARATOR . '*.php') ?: [];
                sort($layerFiles);
                foreach ($layerFiles as $layerPath) {
                    $identity = preg_match(MapLayerSource::FILENAME_PATTERN, basename($layerPath), $matches) === 1
                        ? ['name' => $matches['name'], 'order' => (int) $matches['order'], 'decoration' => $matches['kind'] === 'deco']
                        : null;
                    if ($identity !== null) {
                        $layerPaths[$layerPath] = $identity;
                    }
                }
            }

            $maps[$id] = new self($id, $path, is_file($eventPath) ? $eventPath : null, $layerPaths, $legacy);
        }

        ksort($maps);

        return $maps;
    }
}
