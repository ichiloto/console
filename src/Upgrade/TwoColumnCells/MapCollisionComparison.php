<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Engine\Events\Enumerations\CollisionType;
use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\Field\MapCollisionResolver;
use Ichiloto\Engine\Field\MapLayerSet;

/**
 * Compares a converted map's collision with what its columns had before.
 * The new collision is the engine's own per-cell rule over the converted
 * layers. The old collision is the same composition with every column read
 * as a cell of its own character, which is what one-column cells were.
 */
final readonly class MapCollisionComparison
{
    /** @var list<list<int>> The engine's collision per cell. */
    public array $cells;
    /** @var list<list<int>> The one-column collision per original column. */
    public array $columns;

    /**
     * @param MapLayerSet $layers The converted layers.
     * @param array<int|string, mixed> $dictionary The project's collision dictionary.
     * @param list<int> $originalWidths Each row's width before conversion, in columns.
     */
    public function __construct(MapLayerSet $layers, array $dictionary, array $originalWidths)
    {
        $this->cells = MapCollisionResolver::resolveLayers($layers, $dictionary);
        $flat = array_filter($dictionary, static fn(mixed $value): bool => $value instanceof CollisionType);
        $gameplay = [];

        foreach ($layers->layers as $layer) {
            if (! $layer->decoration) {
                $section = $dictionary[$layer->name] ?? [];
                $gameplay[] = [$layer, (is_array($section) ? $section : []) + $flat];
            }
        }

        $columns = [];

        foreach ($this->cells as $y => $row) {
            $columns[$y] = [];
            $width = $originalWidths[$y] ?? count($row) * MapCell::COLUMNS;

            for ($column = 0; $column < $width; $column++) {
                $cell = intdiv($column, MapCell::COLUMNS);
                $kind = CollisionType::SOLID;

                for ($index = count($gameplay) - 1; $index >= 0; $index--) {
                    [$layer, $types] = $gameplay[$index];
                    $characters = MapCell::getCharacters($layer->glyphs[$y][$cell] ?? MapCell::BLANK);
                    // A two-column glyph was one character covering both columns.
                    $character = $characters[count($characters) === 1 ? 0 : $column % MapCell::COLUMNS] ?? ' ';

                    if ($index !== 0 && trim($character) === '') {
                        continue;
                    }

                    $type = MapCollisionResolver::resolveCell($character, $types);

                    if ($type !== CollisionType::PASS_THROUGH) {
                        $kind = $type;
                        break;
                    }
                }

                $columns[$y][$column] = $kind->value;
            }
        }

        $this->columns = $columns;
    }

    /**
     * Cells that are solid now although a column they cover was not: a wall
     * character paired with a floor character, such as a one-column doorway.
     *
     * @return array<int, list<int>> Cell columns by row.
     */
    public function getNewlySolidCells(): array
    {
        $cells = [];

        foreach ($this->cells as $y => $row) {
            foreach ($row as $x => $kind) {
                if ($this->isNewlySolidCell($x, $y)) {
                    $cells[$y][] = $x;
                }
            }
        }

        return $cells;
    }

    /** Whether the engine lets the player walk onto ground of this kind (MapManager::canMoveTo). */
    public static function isWalkable(?int $kind): bool
    {
        return $kind !== null && $kind !== CollisionType::SOLID->value && $kind !== CollisionType::NPC->value;
    }

    /** Whether a cell is solid now although a column it covers was not. */
    public function isNewlySolidCell(int $x, int $y): bool
    {
        if (($this->cells[$y][$x] ?? null) !== CollisionType::SOLID->value) {
            return false;
        }

        foreach ([$x * MapCell::COLUMNS, $x * MapCell::COLUMNS + 1] as $column) {
            if (isset($this->columns[$y][$column]) && $this->columns[$y][$column] !== CollisionType::SOLID->value) {
                return true;
            }
        }

        return false;
    }

    /**
     * Whether something that stood on an old column now stands on a solid
     * cell although that column was not solid. Null when the position is
     * outside the map.
     */
    public function isNewlySolidColumn(int $column, int $y): ?bool
    {
        $cell = FieldCoordinateConverter::getCell($column);

        if (! isset($this->cells[$y][$cell])) {
            return null;
        }

        return $this->cells[$y][$cell] === CollisionType::SOLID->value
            && ($this->columns[$y][$column] ?? CollisionType::SOLID->value) !== CollisionType::SOLID->value;
    }
}
