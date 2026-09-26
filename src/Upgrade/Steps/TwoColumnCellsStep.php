<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\Steps;

use Ichiloto\Console\Support\SaveCompatibilityMetadata;
use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\ProjectUpgradeFollowUp;
use Ichiloto\Console\Upgrade\ProjectUpgradePlan;
use Ichiloto\Console\Upgrade\ProjectUpgradeReport;
use Ichiloto\Console\Upgrade\ProjectUpgradeStepInterface;
use Ichiloto\Console\Upgrade\TwoColumnCells\FieldCoordinateConverter;
use Ichiloto\Console\Upgrade\TwoColumnCells\GridSourceConverter;
use Ichiloto\Console\Upgrade\TwoColumnCells\MapCollisionComparison;
use Ichiloto\Console\Upgrade\TwoColumnCells\MapReachabilityComparison;
use Ichiloto\Console\Upgrade\TwoColumnCells\ProjectMap;
use Ichiloto\Console\Upgrade\TwoColumnCells\SaveMigrationAppender;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapLayer;
use Ichiloto\Engine\Field\MapLayerSet;
use Ichiloto\Engine\IO\Console\TerminalText;
use Ichiloto\Engine\IO\SaveCompatibility\TwoColumnCellsMigration;
use InvalidArgumentException;
use RecursiveDirectoryIterator;
use RecursiveIteratorIterator;
use Throwable;

/**
 * Format 2: square map cells, two terminal columns wide. Every map layer and
 * event layer is regrouped into two-column cells with its terminal art
 * unchanged, every field x coordinate is halved, the retired tile crops are
 * removed, and saved games gain a content migration. What needs a person is
 * reported: layers that no longer load, cells that became solid, things now
 * standing on them, and move route steps whose length depends on where they
 * start.
 */
final readonly class TwoColumnCellsStep implements ProjectUpgradeStepInterface
{
    public const int VERSION = 2;

    public const string BLOCKING = 'Maps that will not load until fixed';
    public const string MANUAL = 'Values to convert by hand';
    public const string SOLID_CELLS = 'Cells that became solid';
    public const string UNREACHABLE = 'No longer reachable';
    public const string CUT_OFF = 'Walkable regions cut off';
    public const string SOLID_POSITIONS = 'NPCs, events and spawn points now on solid cells';
    public const string SHARED_CELLS = 'NPCs now sharing a cell';
    public const string ROUTE_STEPS = 'Move route steps to check';
    public const string MOVED_GLYPHS = 'Two-column glyphs moved right';
    public const string TILE_CROPS = 'Maps shown with terminal glyphs';
    public const string NEXT = 'Next';

    private const string COLLISIONS_PATH = 'assets/Maps/collisions.php';
    private const string EVENTS_DIRECTORY = 'assets/Events';
    private const string CINEMATICS_DIRECTORY = 'assets/Cutscenes/Cinematics';
    private const string SYSTEM_PATH = 'assets/Data/system.php';

    public function __construct(
        private GridSourceConverter $grids = new GridSourceConverter(),
        private FieldCoordinateConverter $coordinates = new FieldCoordinateConverter(),
        private SaveMigrationAppender $saves = new SaveMigrationAppender(),
    ) {
    }

    public function getTargetVersion(): int
    {
        return self::VERSION;
    }

    public function createPlan(ProjectUpgradeContext $context): ProjectUpgradePlan
    {
        $writes = [];
        $followUps = [];
        $maps = ProjectMap::findAll($context->root);
        $dictionary = $this->loadCollisionDictionary($context, $followUps);
        $gridSummary = ['files' => 0, 'changed' => 0, 'padded' => 0, 'moved' => 0];
        $states = [];

        foreach ($maps as $map) {
            $states[$map->id] = $this->convertMap($context, $map, $dictionary, $writes, $followUps, $gridSummary);
        }

        $comparisons = array_map(static fn(?array $state): ?MapCollisionComparison => $state['comparison'] ?? null, $states);
        $coordinateSummary = ['halved' => 0, 'files' => 0, 'steps' => 0, 'crops' => 0];
        $npcs = $positions = $areas = [];

        foreach ($this->findCoordinateFiles($context, $maps) as $path => [$kind, $mapId]) {
            $converted = $this->convertCoordinates($context, $path, $kind, $mapId, $comparisons, $writes, $followUps, $coordinateSummary, $npcs);
            array_push($positions, ...$converted['positions']);
            array_push($areas, ...$converted['areas']);
        }

        $this->compareReachability($states, $positions, $areas, $followUps);

        foreach ($npcs as $cell => $labels) {
            // NPCs that already shared a column were already in one cell.
            if (count($labels) > 1) {
                $labels = array_merge(...array_values($labels));
                $followUps[] = new ProjectUpgradeFollowUp(self::SHARED_CELLS, sprintf(
                    '%s: %s now share one cell; the game finds only the first NPC in a cell, so move one unless their conditions never overlap.',
                    $cell,
                    implode(' and ', $labels),
                ));
            }
        }

        $changes = [
            sprintf(
                'Regroup %d %s into two-column cells: %d of %d grid files change (%d %s padded to an even width, %d two-column %s moved right).',
                count($maps), count($maps) === 1 ? 'map' : 'maps',
                $gridSummary['changed'], $gridSummary['files'],
                $gridSummary['padded'], $gridSummary['padded'] === 1 ? 'row' : 'rows',
                $gridSummary['moved'], $gridSummary['moved'] === 1 ? 'glyph' : 'glyphs',
            ),
            sprintf(
                'Halve %d field x %s in %d %s.',
                $coordinateSummary['halved'], $coordinateSummary['halved'] === 1 ? 'coordinate' : 'coordinates',
                $coordinateSummary['files'], $coordinateSummary['files'] === 1 ? 'file' : 'files',
            ),
        ];

        if ($coordinateSummary['steps'] > 0) {
            $changes[] = sprintf('Halve %d horizontal move route %s (each is listed for review).', $coordinateSummary['steps'], $coordinateSummary['steps'] === 1 ? 'step' : 'steps');
        }

        if ($coordinateSummary['crops'] > 0) {
            $changes[] = sprintf('Remove retired tiles2d crops from %d %s.', $coordinateSummary['crops'], $coordinateSummary['crops'] === 1 ? 'map' : 'maps');
        }

        $changes[] = $this->planSaveMigration($context, $writes, $followUps);
        $blocking = count(array_filter($followUps, static fn(ProjectUpgradeFollowUp $item): bool => $item->blocking));
        $changes[] = sprintf(
            'Report %d %s that block loading and %d for review or hand conversion.',
            $blocking, $blocking === 1 ? 'item' : 'items', count($followUps) - $blocking,
        );
        $followUps[] = new ProjectUpgradeFollowUp(self::NEXT, 'Re-proportion furniture and rooms for square cells where they look stretched; that is the author\'s work after the conversion.');
        $followUps[] = new ProjectUpgradeFollowUp(self::NEXT, 'Run `ichiloto validate`, then play through the converted maps and cinematics.');

        return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION], $changes, $writes, $followUps);
    }

    public function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): ProjectUpgradeReport
    {
        return ProjectUpgradeReport::applyPlan($context, $plan);
    }

    /**
     * Converts one map's layers and event layer and compares its collision.
     *
     * @param array<int|string, mixed>|null $dictionary
     * @param array<string, string> $writes
     * @param list<ProjectUpgradeFollowUp> $followUps
     * @param array{files: int, changed: int, padded: int, moved: int} $summary
     * @return array{comparison: MapCollisionComparison, events: list<list<string>>, glyphs: list<list<string>>}|null
     *   The converted map's collision comparison, event cells and composed
     *   glyphs; null when it does not load or has no collision dictionary.
     */
    private function convertMap(
        ProjectUpgradeContext $context,
        ProjectMap $map,
        ?array $dictionary,
        array &$writes,
        array &$followUps,
        array &$summary,
    ): ?array {
        $layers = [];
        $originalWidths = [];
        $loads = true;

        foreach ($map->layerPaths as $path => $identity) {
            $converted = $this->convertGrid($context, $path, $writes, $followUps, $summary);

            if ($converted === null) {
                $loads = false;
                continue;
            }

            try {
                $layers[] = new MapLayer($identity['name'], $identity['order'], $identity['decoration'], $context->getRelativePath($path), $converted['text']);
            } catch (InvalidArgumentException $error) {
                $followUps[] = new ProjectUpgradeFollowUp(self::BLOCKING, $error->getMessage(), true);
                $loads = false;
            }

            if (! $identity['decoration']) {
                foreach ($converted['originalWidths'] as $row => $width) {
                    $originalWidths[$row] = max($originalWidths[$row] ?? 0, $width);
                }
            }
        }

        $eventGrid = null;

        if ($map->eventPath !== null) {
            $converted = $this->convertGrid($context, $map->eventPath, $writes, $followUps, $summary);
            $eventGrid = $converted === null ? null : $this->readEventGrid($context->getRelativePath($map->eventPath), $converted['text'], $followUps);
        }

        if (! $loads || $layers === []) {
            return null;
        }

        try {
            $set = new MapLayerSet($layers, $map->legacy);
            if ($eventGrid !== null) {
                $set->assertMatchingGrid($eventGrid, 'Event map ' . $context->getRelativePath((string) $map->eventPath));
            }
        } catch (InvalidArgumentException $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::BLOCKING, "{$map->id}: {$error->getMessage()}", true);
            return null;
        }

        if ($dictionary === null) {
            return null;
        }

        try {
            $comparison = new MapCollisionComparison($set, $dictionary, $originalWidths);
        } catch (InvalidArgumentException $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, "{$map->id}: collision could not be compared: {$error->getMessage()}");
            return null;
        }

        $events = [];
        foreach ($eventGrid ?? [] as $y => $row) {
            foreach ($row as $x => $cell) {
                $marker = $this->readMarker($cell);
                if ($marker !== null && $comparison->isNewlySolidCell($x, $y)) {
                    $events[$marker][$y][] = $x;
                }
            }
        }

        foreach ($events as $marker => $markerCells) {
            $followUps[] = new ProjectUpgradeFollowUp(self::SOLID_POSITIONS, sprintf(
                '%s: event %s covers newly solid cells (%s).',
                $map->id,
                $marker,
                $this->describeCells($markerCells),
            ));
        }

        return [
            'comparison' => $comparison,
            'events' => $eventGrid ?? [],
            'glyphs' => array_map(static fn(array $row): array => array_map(TerminalText::stripAnsi(...), $row), $set->getComposedGrid()),
        ];
    }

    /**
     * Compares where the player can walk on each map before and after, from
     * the map's entry points, and reports what can no longer be reached.
     *
     * @param array<string, array{comparison: MapCollisionComparison, events: list<list<string>>, glyphs: list<list<string>>}|null> $states
     * @param list<array{map: string, x: int, y: int, label: string, npc: bool}> $positions
     * @param list<array{map: string, x: int, y: int, width: int, height: int, label: string}> $areas
     * @param list<ProjectUpgradeFollowUp> $followUps
     */
    private function compareReachability(array $states, array $positions, array $areas, array &$followUps): void
    {
        foreach ($states as $mapId => $state) {
            if ($state === null) {
                continue;
            }

            $entries = $targets = [];

            foreach ($positions as $position) {
                if ($position['map'] !== $mapId) {
                    continue;
                }
                if ($position['npc']) {
                    $targets[] = ['label' => "NPC at {$position['label']}", 'columns' => [[$position['x'], $position['y']]]];
                } else {
                    $entries[] = ['x' => $position['x'], 'y' => $position['y'], 'label' => $position['label']];
                }
            }

            foreach ($areas as $area) {
                if ($area['map'] !== $mapId) {
                    continue;
                }
                $columns = [];
                for ($y = $area['y']; $y < $area['y'] + $area['height']; $y++) {
                    for ($x = $area['x']; $x < $area['x'] + $area['width']; $x++) {
                        $columns[] = [$x, $y];
                    }
                }
                $targets[] = ['label' => "trigger at {$area['label']}", 'columns' => $columns];
            }

            foreach ($this->getEventColumns($state['events']) as $marker => $columns) {
                $targets[] = ['label' => "event {$marker}", 'columns' => $columns];
            }

            $solid = count(array_merge([], ...array_values($state['comparison']->getNewlySolidCells())));
            $followUps[] = new ProjectUpgradeFollowUp(self::SOLID_CELLS, sprintf(
                '%s: %d %s became solid; %s.',
                $mapId,
                $solid,
                $solid === 1 ? 'cell' : 'cells',
                $entries === []
                    ? 'no entry point is known, so reachability was not compared'
                    : sprintf('reachability was compared from %d entry %s', count($entries), count($entries) === 1 ? 'point' : 'points'),
            ));

            if ($entries === []) {
                continue;
            }

            $result = new MapReachabilityComparison($state['comparison'])->compareReachability($entries, $targets);

            foreach ($result['targets'] as $label) {
                $followUps[] = new ProjectUpgradeFollowUp(self::UNREACHABLE, "{$mapId}: {$label} can no longer be reached from the map's entry points.");
            }

            foreach ($result['entries'] as $entry) {
                $followUps[] = new ProjectUpgradeFollowUp(self::UNREACHABLE, sprintf(
                    '%s: the arrival at %s no longer connects to %d other %s it reached before, such as %s.',
                    $mapId,
                    $entry['label'],
                    $entry['lost'],
                    $entry['lost'] === 1 ? 'entry point' : 'entry points',
                    $entry['example'],
                ));
            }

            foreach ($result['regions'] as $region) {
                [$x, $y] = $region['cell'];
                $choke = $region['choke'] === null
                    ? 'no single newly solid cell borders it'
                    : sprintf('likely closed by cell (%d, %d), "%s", which became solid', $region['choke'][0], $region['choke'][1], $state['glyphs'][$region['choke'][1]][$region['choke'][0]] ?? '');
                $followUps[] = new ProjectUpgradeFollowUp(self::CUT_OFF, sprintf(
                    '%s: %d walkable %s around cell (%d, %d) can no longer be reached; %s.',
                    $mapId,
                    $region['size'],
                    $region['size'] === 1 ? 'cell' : 'cells',
                    $x,
                    $y,
                    $choke,
                ));
            }
        }
    }

    /**
     * The old columns each event marker occupied, read from its converted cells.
     *
     * @param list<list<string>> $events
     * @return array<string, list<array{int, int}>>
     */
    private function getEventColumns(array $events): array
    {
        $columns = [];

        foreach ($events as $y => $row) {
            foreach ($row as $x => $cell) {
                $marker = $this->readMarker($cell);
                if ($marker === null) {
                    continue;
                }
                $characters = MapCell::getCharacters($cell);
                foreach ($characters as $index => $character) {
                    if ($character === $marker || count($characters) === 1) {
                        $columns[$marker][] = [$x * MapCell::COLUMNS + (count($characters) === 1 ? 0 : $index), $y];
                    }
                }
            }
        }

        return $columns;
    }

    /**
     * @param array<string, string> $writes
     * @param list<ProjectUpgradeFollowUp> $followUps
     * @param array{files: int, changed: int, padded: int, moved: int} $summary
     * @return array{text: string, originalWidths: list<int>}|null
     */
    private function convertGrid(ProjectUpgradeContext $context, string $path, array &$writes, array &$followUps, array &$summary): ?array
    {
        $relative = $context->getRelativePath($path);
        $source = (string) file_get_contents($path);
        $summary['files']++;

        try {
            $converted = $this->grids->convertSource($source, $relative);
        } catch (InvalidArgumentException $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::BLOCKING, "{$relative}: {$error->getMessage()}", true);
            return null;
        }

        if ($converted['source'] !== $source) {
            $writes[$path] = $converted['source'];
            $summary['changed']++;
        }

        $summary['padded'] += $converted['paddedRows'];
        $summary['moved'] += count($converted['insertions']);

        foreach ($converted['insertions'] as $insertion) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MOVED_GLYPHS, sprintf(
                '%s row %d, column %d: a space was inserted before %s so it starts a cell; the rest of the row moved one column right, so check it against the map\'s other layers.',
                $relative,
                $insertion['row'],
                $insertion['column'],
                $insertion['glyph'],
            ));
        }

        foreach ($converted['errors'] as $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::BLOCKING, "{$relative} {$error}.", true);
        }

        return $converted['errors'] === [] ? $converted : null;
    }

    /**
     * Reads a converted event layer, reporting each cell with two different markers.
     *
     * @param list<ProjectUpgradeFollowUp> $followUps
     * @return list<list<string>>|null
     */
    private function readEventGrid(string $relative, string $text, array &$followUps): ?array
    {
        try {
            $grid = MapLayer::parseGrid($text, $relative);
        } catch (InvalidArgumentException $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::BLOCKING, $error->getMessage(), true);
            return null;
        }

        foreach ($grid as $y => $row) {
            foreach ($row as $x => $cell) {
                try {
                    MapCell::getMarker($cell, "{$relative} row {$y}, cell {$x}");
                } catch (InvalidArgumentException $error) {
                    $followUps[] = new ProjectUpgradeFollowUp(self::BLOCKING, $error->getMessage() . ' Keep one marker per cell.', true);
                }
            }
        }

        return $grid;
    }

    private function readMarker(string $cell): ?string
    {
        try {
            return MapCell::getMarker($cell);
        } catch (InvalidArgumentException) {
            return null;
        }
    }

    /**
     * Every authored file that can hold field coordinates, with what it holds.
     *
     * @param array<string, ProjectMap> $maps
     * @return array<string, array{string, ?string}> Kind and map id by path.
     */
    private function findCoordinateFiles(ProjectUpgradeContext $context, array $maps): array
    {
        $files = [];

        foreach ($maps as $map) {
            $files[$map->dataPath] = [FieldCoordinateConverter::MAP_DATA, $map->id];
        }

        foreach ($this->findPhpFiles($context->getPath(self::EVENTS_DIRECTORY)) as $path) {
            $files[$path] = [FieldCoordinateConverter::COMMANDS, null];
        }

        foreach ($this->findPhpFiles($context->getPath(self::CINEMATICS_DIRECTORY)) as $path) {
            $files[$path] = [str_ends_with($path, '.data.php') ? FieldCoordinateConverter::CINEMATIC : FieldCoordinateConverter::COMMANDS, null];
        }

        $system = $context->getPath(self::SYSTEM_PATH);
        if (is_file($system)) {
            $files[$system] = [FieldCoordinateConverter::SYSTEM, null];
        }

        return $files;
    }

    /** @return list<string> */
    private function findPhpFiles(string $directory): array
    {
        if (! is_dir($directory)) {
            return [];
        }

        $paths = [];
        foreach (new RecursiveIteratorIterator(new RecursiveDirectoryIterator($directory, RecursiveDirectoryIterator::SKIP_DOTS)) as $file) {
            if (str_ends_with($file->getFilename(), '.php')) {
                $paths[] = $file->getPathname();
            }
        }
        sort($paths);

        return $paths;
    }

    /**
     * @param array<string, MapCollisionComparison|null> $comparisons
     * @param array<string, string> $writes
     * @param list<ProjectUpgradeFollowUp> $followUps
     * @param array{halved: int, files: int, steps: int, crops: int} $summary
     * @param array<string, array<int, list<string>>> $npcs NPC labels by map and new cell, then old column.
     * @return array{positions: list<array{map: string, x: int, y: int, label: string, npc: bool}>, areas: list<array{map: string, x: int, y: int, width: int, height: int, label: string}>}
     */
    private function convertCoordinates(
        ProjectUpgradeContext $context,
        string $path,
        string $kind,
        ?string $mapId,
        array $comparisons,
        array &$writes,
        array &$followUps,
        array &$summary,
        array &$npcs,
    ): array {
        $relative = $context->getRelativePath($path);
        $source = (string) file_get_contents($path);

        try {
            $converted = $this->coordinates->convertSource($source, $relative, $kind, $mapId);
        } catch (InvalidArgumentException $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, "{$relative}: the file could not be read ({$error->getMessage()}); halve its field x coordinates by hand.");
            return ['positions' => [], 'areas' => []];
        }

        $updated = $converted['source'];
        $summary['halved'] += $converted['halved'];
        $summary['steps'] += count($converted['steps']);

        foreach ($converted['manual'] as $message) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, $message);
        }

        foreach ($converted['steps'] as $message) {
            $followUps[] = new ProjectUpgradeFollowUp(self::ROUTE_STEPS, $message);
        }

        foreach ($converted['positions'] as $position) {
            if ($position['npc']) {
                $cell = sprintf('%s cell (%d, %d)', $position['map'], FieldCoordinateConverter::getCell($position['x']), $position['y']);
                $npcs[$cell][$position['x']][] = $position['label'];
            }

            $newlySolid = ($comparisons[$position['map']] ?? null)?->isNewlySolidColumn($position['x'], $position['y']);
            if ($newlySolid === true) {
                $followUps[] = new ProjectUpgradeFollowUp(self::SOLID_POSITIONS, sprintf(
                    '%s now stands on solid cell (%d, %d) of %s.',
                    $position['label'],
                    FieldCoordinateConverter::getCell($position['x']),
                    $position['y'],
                    $position['map'],
                ));
            }
        }

        if ($converted['tiles2d']) {
            try {
                $document = PhpArraySourceDocument::parse($updated);
                $updated = $document->withEdits([$document->removeEntryEdit(['tiles2d'])])->source;
                $summary['crops']++;
                $followUps[] = new ProjectUpgradeFollowUp(self::TILE_CROPS, "{$mapId}: tiles2d was removed; the map shows its terminal glyphs in graphical renderers until it has a tileset.");
            } catch (Throwable $error) {
                $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, "{$relative}: remove the retired tiles2d entry by hand ({$error->getMessage()}); the engine no longer reads it.");
            }
        }

        if ($updated !== $source) {
            $writes[$path] = $updated;
            $summary['files']++;
        }

        return ['positions' => $converted['positions'], 'areas' => $converted['areas']];
    }

    /**
     * @param array<string, string> $writes
     * @param list<ProjectUpgradeFollowUp> $followUps
     */
    private function planSaveMigration(ProjectUpgradeContext $context, array &$writes, array &$followUps): string
    {
        $path = $context->getPath(SaveMetadataStep::MANIFEST_PATH);
        // Format 1 creates the manifest; planned before it runs, start from what it will write.
        $source = is_file($path) ? (string) file_get_contents($path) : SaveCompatibilityMetadata::renderBaseline();

        try {
            $migration = $this->saves->appendMigration($source, TwoColumnCellsMigration::class);
        } catch (Throwable $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, sprintf(
                '%s: %s Register %s as the last content migration by hand, or saved games will place the player in the wrong column.',
                SaveMetadataStep::MANIFEST_PATH,
                $error->getMessage(),
                TwoColumnCellsMigration::class,
            ));

            return 'Leave the save compatibility manifest unchanged (it could not be read; see the report).';
        }

        if (! $migration['added']) {
            return 'Keep the save migration for two-column cells already registered in the save compatibility manifest.';
        }

        $writes[$path] = $migration['source'];

        return sprintf(
            'Add save migration %d to %d (TwoColumnCellsMigration), so saved games reopen in the cell that holds their column.',
            $migration['from'],
            $migration['to'],
        );
    }

    /**
     * @param list<ProjectUpgradeFollowUp> $followUps
     * @return array<int|string, mixed>|null
     */
    private function loadCollisionDictionary(ProjectUpgradeContext $context, array &$followUps): ?array
    {
        $path = $context->getPath(self::COLLISIONS_PATH);

        if (! is_file($path)) {
            return null;
        }

        try {
            // The dictionary is authored PHP the engine requires the same way; grids are never evaluated.
            $dictionary = (static fn(string $file): mixed => require $file)($path);
        } catch (Throwable $error) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, sprintf('%s could not be loaded (%s), so cells that became solid were not checked.', self::COLLISIONS_PATH, $error->getMessage()));
            return null;
        }

        if (! is_array($dictionary)) {
            $followUps[] = new ProjectUpgradeFollowUp(self::MANUAL, self::COLLISIONS_PATH . ' does not return an array, so cells that became solid were not checked.');
            return null;
        }

        return $dictionary;
    }

    /** @param array<int, list<int>> $cells Cell columns by row. */
    private function describeCells(array $cells): string
    {
        ksort($cells);
        $rows = [];

        foreach ($cells as $y => $columns) {
            $rows[] = sprintf('row %d: %s %s', $y, count($columns) === 1 ? 'cell' : 'cells', implode(', ', $columns));
        }

        return implode('; ', $rows);
    }
}
