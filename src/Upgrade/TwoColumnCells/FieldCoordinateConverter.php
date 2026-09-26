<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Engine\Cutscenes\Cinematics\CinematicCommandSchema;
use Ichiloto\Engine\Events\Interpreter\MovementRouteRunner;
use Ichiloto\Engine\Field\MapCell;
use InvalidArgumentException;

/**
 * Halves the field x coordinates in one authored PHP file, rounding down, so
 * each names the two-column cell that now holds its column. Only integer
 * literal tokens change. Which values are field coordinates is decided from
 * the engine's own vocabulary: the command shapes and staged actor fields of
 * the cinematic schema, the `position` subject kind, movement route
 * waypoints and steps, and the map, trigger and start position fields the
 * loaders read. An `x` outside those is reported rather than guessed, and so
 * is a coordinate that is not a literal.
 */
final class FieldCoordinateConverter
{
    /** A map's `<leaf>.data.php`. */
    public const string MAP_DATA = 'map data';
    /** A command list: common events and cinematic scripts. */
    public const string COMMANDS = 'commands';
    /** A cinematic definition: its cast and finalizer. */
    public const string CINEMATIC = 'cinematic';
    /** assets/Data/system.php: only its start positions hold field coordinates. */
    public const string SYSTEM = 'system';

    private const array STEP_FIELDS = ['direction', 'count', 'faceOnly', 'seconds'];
    private const array HORIZONTAL_DIRECTIONS = ['left', 'right'];

    /**
     * Converts one file.
     *
     * @param string $source The file's bytes.
     * @param string $displayPath The project-relative path, for messages.
     * @param string $kind What the file holds: one of the kind constants.
     * @param string|null $mapId The map a map data file describes.
     * @return array{
     *   source: string,
     *   halved: int,
     *   steps: list<string>,
     *   review: list<string>,
     *   manual: list<string>,
     *   positions: list<array{map: string, x: int, y: int, label: string, npc: bool}>,
     *   areas: list<array{map: string, x: int, y: int, width: int, height: int, label: string}>,
     *   tiles2d: bool
     * }
     *   The rewritten source; how many coordinates changed; each horizontal
     *   move route step, described; values a person must convert; positions
     *   to check against the new collision, by map, with their old column
     *   and whether each is an NPC; trigger areas on the map, in old columns;
     *   and whether the file declares tile crops.
     * @throws InvalidArgumentException When the file is not valid PHP.
     */
    public function convertSource(string $source, string $displayPath, string $kind, ?string $mapId = null): array
    {
        $file = new PhpLiteralScanner()->scanSource($source, $displayPath);
        $result = ['halved' => 0, 'steps' => [], 'review' => [], 'manual' => [], 'positions' => [], 'areas' => [], 'tiles2d' => false];
        $edits = [];

        foreach ($file->getArrays() as $array) {
            if ($kind === self::MAP_DATA && $array->parent === null && $array->getEntry('tiles2d') !== null) {
                $result['tiles2d'] = true;
            }

            if ($this->isOutsideFieldData($array, $kind)) {
                continue;
            }

            $this->convertStep($array, $displayPath, $edits, $result);

            if ($array->getEntry('x') === null) {
                continue;
            }

            $role = $this->getRole($array, $kind);
            $where = sprintf('%s:%d %s', $displayPath, $array->getEntry('x')->line, $this->describe($array));

            match ($role) {
                'point' => $this->convertPoint($array, $where, $edits, $result),
                'rect' => $this->convertRect($array, $where, $edits, $result),
                'screen', 'region' => null,
                default => $result['manual'][] = "{$where}: an x the upgrade could not identify as a field coordinate was left unchanged; halve it if it is one.",
            };

            $column = $array->getEntry('x')->integer;
            $row = $array->getEntry('y')?->integer;

            if ($role === 'rect' && $mapId !== null && $column !== null && $row !== null && $array->parentKey !== 'wanderArea') {
                $width = $array->getEntry('width') === null ? 1 : $array->getEntry('width')->integer;
                $height = $array->getEntry('height') === null ? 1 : $array->getEntry('height')->integer;
                if ($width !== null && $height !== null) {
                    $result['areas'][] = ['map' => $mapId, 'x' => $column, 'y' => $row, 'width' => $width, 'height' => $height, 'label' => $where];
                }
            }

            $target = $role === 'point' ? $this->getPositionMap($array, $kind, $mapId) : null;

            if (is_string($target) && $column !== null && $row !== null) {
                $result['positions'][] = [
                    'map' => $target,
                    'x' => $column,
                    'y' => $row,
                    'label' => $where,
                    'npc' => $kind === self::MAP_DATA && $this->isListedSubject($array, $kind),
                ];
            }
        }

        usort($edits, static fn(array $a, array $b): int => $b[0] <=> $a[0]);
        foreach ($edits as [$start, $end, $text]) {
            $source = substr($source, 0, $start) . $text . substr($source, $end);
        }

        // The rewritten file must still be valid PHP.
        new PhpLiteralScanner()->scanSource($source, $displayPath);

        return ['source' => $source] + $result;
    }

    /** Arrays whose x values are not field positions at all: pixel crops, and data outside start positions. */
    private function isOutsideFieldData(PhpLiteral $array, string $kind): bool
    {
        return match ($kind) {
            self::MAP_DATA => $array->isUnderKey('tiles2d'),
            self::SYSTEM => ! $array->isUnderKey('startingPositions'),
            default => false,
        };
    }

    /**
     * What an array holding an `x` is: a field point, a field rectangle, a
     * screen position, or unknown.
     */
    private function getRole(PhpLiteral $array, string $kind): string
    {
        $type = $array->getString('type');
        $parent = $array->parent;

        if ($type !== null && in_array($type, self::getPositionCommandTypes(), true)) {
            return 'point';
        }

        $subjectKind = $array->getString('kind');
        if ($subjectKind === 'position') {
            return 'point';
        }
        if ($subjectKind === 'screen_position') {
            return 'screen';
        }

        if ($array->parentKey === 'actor' && $parent !== null && $parent->getString('type') === 'stage_actor') {
            return 'point';
        }

        if ($parent !== null && $parent->parentKey === 'waypoints') {
            return 'point';
        }

        if (in_array($array->parentKey, ['spawnPoint', 'spawn_point'], true)) {
            return 'point';
        }

        if (in_array($array->parentKey, ['wanderArea', 'trigger_area'], true)
            || ($kind === self::MAP_DATA && $array->parentKey === 'area')) {
            return 'rect';
        }

        if ($this->isListedSubject($array, $kind)) {
            return 'point';
        }

        // A region map station places the map on its region's schematic, not on the field.
        if ($kind === self::MAP_DATA && $array->parentKey === 'station' && $parent?->parent === null) {
            return 'region';
        }

        return 'unknown';
    }

    /**
     * Whether an array is one of a map's NPCs or a cinematic's cast: an entry
     * of the top-level `npcs` or `cast` list, or an array with the subject's
     * required name or id written inside such an entry (built by a helper
     * closure and spread into the list, for example).
     */
    private function isListedSubject(PhpLiteral $array, string $kind): bool
    {
        $listKey = match ($kind) {
            self::MAP_DATA => 'npcs',
            self::CINEMATIC => 'cast',
            default => null,
        };
        $list = $array->enclosingArray;

        if ($listKey === null || $list === null || $list->parentKey !== $listKey
            || $list->parent === null || $list->parent->enclosingArray !== null) {
            return false;
        }

        return $array->parent === $list || $array->getEntry('name') !== null || $array->getEntry('id') !== null;
    }

    /**
     * Command types that carry a field x of their own: those whose finalizer
     * shape requires one, and staging an actor, whose fields include one.
     *
     * @return list<string>
     */
    private static function getPositionCommandTypes(): array
    {
        $types = [];
        foreach (CinematicCommandSchema::FINALIZER_COMMAND_SHAPES as $type => $shape) {
            if (array_key_exists('x', $shape['required'] ?? []) || array_key_exists('x', $shape['optional'] ?? [])) {
                $types[] = $type;
            }
        }
        if (in_array('x', CinematicCommandSchema::STAGED_ACTOR_FIELDS, true)) {
            $types[] = 'stage_actor';
        }

        return $types;
    }

    /**
     * The map a point stands on, when it should be checked against the new
     * collision: a transfer's map, a spawn point's destination (or its own
     * map), an NPC's map. Null when the point is not checked.
     */
    private function getPositionMap(PhpLiteral $array, string $kind, ?string $mapId): ?string
    {
        if ($array->getString('type') === 'transfer') {
            return $array->getString('map');
        }

        if (in_array($array->parentKey, ['spawnPoint', 'spawn_point'], true)) {
            return $array->parent?->getString('destinationMap') ?? ($kind === self::MAP_DATA ? $mapId : null);
        }

        if ($kind === self::MAP_DATA && $this->isListedSubject($array, $kind)) {
            return $mapId;
        }

        return null;
    }

    /** @param list<array{int, int, string}> $edits */
    private function convertPoint(PhpLiteral $array, string $where, array &$edits, array &$result): void
    {
        $x = $array->getEntry('x');
        $value = $x->integer;

        if ($value === null) {
            $result['manual'][] = "{$where}: x is not an integer literal; halve it by hand (rounding down).";
            return;
        }

        $this->replaceInteger($x, self::getCell($value), $edits, $result);
    }

    /**
     * A rectangle keeps covering the cells its old columns covered: its x is
     * halved and its width becomes the cells from its first column to its last.
     *
     * @param list<array{int, int, string}> $edits
     */
    private function convertRect(PhpLiteral $array, string $where, array &$edits, array &$result): void
    {
        $x = $array->getEntry('x');
        $width = $array->getEntry('width');
        $left = $x->integer;
        $columns = $width === null ? 1 : $width->integer;

        if ($left === null || $columns === null) {
            $result['manual'][] = "{$where}: x or width is not an integer literal; convert the area by hand (x rounds down, width covers the same columns).";
            return;
        }

        $this->replaceInteger($x, self::getCell($left), $edits, $result);

        if ($width !== null) {
            $this->replaceInteger($width, self::getCell($left + $columns - 1) - self::getCell($left) + 1, $edits, $result);
        }
    }

    /**
     * Halves a horizontal move route step count. The exact count depends on
     * the column the route starts from at runtime, so every horizontal step
     * is reported with both possible distances.
     *
     * @param list<array{int, int, string}> $edits
     */
    private function convertStep(PhpLiteral $array, string $displayPath, array &$edits, array &$result): void
    {
        $direction = $array->getString('direction');

        if ($direction === null || ! in_array($direction, MovementRouteRunner::DIRECTIONS, true)
            || array_diff(array_keys($array->entries), self::STEP_FIELDS) !== [] || $array->embedded !== []) {
            return;
        }

        if (! in_array($direction, self::HORIZONTAL_DIRECTIONS, true) || $array->getEntry('faceOnly')?->boolean === true) {
            return;
        }

        $where = sprintf('%s:%d %s', $displayPath, $array->getEntry('direction')->line, $this->describe($array));
        $count = $array->getEntry('count');
        $columns = $count === null ? 1 : $count->integer;

        if ($columns === null) {
            $result['manual'][] = "{$where}: the {$direction} step count is not an integer literal; halve it by hand.";
            return;
        }

        $cells = $columns <= 0 ? $columns : max(1, intdiv($columns, MapCell::COLUMNS));
        $shortest = intdiv($columns, MapCell::COLUMNS);
        $longest = intdiv($columns + 1, MapCell::COLUMNS);

        if ($count !== null) {
            $this->replaceInteger($count, $cells, $edits, $result, countAsCoordinate: false);
        }

        $result['steps'][] = sprintf(
            '%s: %s %d %s now %d %s; %s.',
            $where,
            $direction,
            $columns,
            $columns === 1 ? 'step is' : 'steps are',
            $cells,
            $cells === 1 ? 'cell' : 'cells',
            $shortest === $longest
                ? sprintf('the same distance is %d %s from any starting column', $shortest, $shortest === 1 ? 'cell' : 'cells')
                : sprintf('the same distance is %d or %d cells, depending on the starting column', $shortest, $longest),
        );
    }

    /** @param list<array{int, int, string}> $edits */
    private function replaceInteger(PhpLiteral $literal, int $value, array &$edits, array &$result, bool $countAsCoordinate = true): void
    {
        if ($literal->integer === $value) {
            return;
        }

        $edits[] = [$literal->start, $literal->end, (string) $value];

        if ($countAsCoordinate) {
            $result['halved']++;
        }
    }

    /** The cell holding a one-column x. */
    public static function getCell(int $column): int
    {
        return intdiv($column - ($column < 0 ? MapCell::COLUMNS - 1 : 0), MapCell::COLUMNS);
    }

    private function describe(PhpLiteral $array): string
    {
        $path = $array->getPath();
        $type = $array->getString('type');

        if ($path === '') {
            return $type !== null ? "({$type})" : '';
        }

        return $type !== null ? "{$path} ({$type})" : $path;
    }
}
