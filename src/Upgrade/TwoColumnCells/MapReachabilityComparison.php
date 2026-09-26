<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Engine\Field\MapCell;

/**
 * Compares where the player can walk on a map before and after two-column
 * cells. Walkable ground is flood-filled from the map's entry points (spawn
 * points and transfers that arrive on it, and the new game start) twice:
 * over the old one-column collision, stepping one column at a time, and over
 * the engine's per-cell collision. What was reachable before and is not
 * after is what a person needs to look at; a raw list of cells that became
 * solid is not.
 */
final readonly class MapReachabilityComparison
{
    public function __construct(private MapCollisionComparison $collision)
    {
    }

    /**
     * @param list<array{x: int, y: int, label: string}> $entries Arrival points, by old column.
     * @param list<array{label: string, columns: list<array{int, int}>}> $targets Things to reach,
     *   by the old columns they occupy: event areas, NPCs and transfer triggers. A target is
     *   reachable when one of its positions, or a position beside one, is reachable.
     * @return array{
     *   targets: list<string>,
     *   entries: list<array{label: string, lost: int, example: string}>,
     *   regions: list<array{size: int, cell: array{int, int}, choke: array{int, int}|null}>
     * }
     *   Targets reachable before but not after; entries that no longer connect to
     *   other entries they connected to before; and walkable regions that were
     *   reachable before and are now cut off, each with a representative cell and
     *   the newly solid cell that most likely closed it.
     */
    public function compareReachability(array $entries, array $targets): array
    {
        $entries = $this->removeDuplicateEntries($entries);
        $oldFloods = $newFloods = [];

        foreach ($entries as $index => $entry) {
            $oldFloods[$index] = $this->flood($this->collision->columns, [[$entry['x'], $entry['y']]]);
            $newFloods[$index] = $this->flood($this->collision->cells, [[FieldCoordinateConverter::getCell($entry['x']), $entry['y']]]);
        }

        $oldReach = $oldFloods === [] ? [] : array_replace(...$oldFloods);
        $newReach = $newFloods === [] ? [] : array_replace(...$newFloods);
        $lostTargets = [];

        foreach ($targets as $target) {
            $cells = array_map(static fn(array $column): array => [FieldCoordinateConverter::getCell($column[0]), $column[1]], $target['columns']);
            if ($this->isReached($oldReach, $target['columns']) && ! $this->isReached($newReach, $cells)) {
                $lostTargets[] = $target['label'];
            }
        }

        $lostEntries = [];

        // Each pair of entries is compared once; an entry on ground it cannot
        // stand on still connects through the ground beside it.
        foreach ($entries as $index => $entry) {
            $lost = [];
            foreach (array_slice($entries, $index + 1, preserve_keys: true) as $otherIndex => $other) {
                if ($this->isReached($oldFloods[$index], [[$other['x'], $other['y']]])
                    && ! $this->isReached($newFloods[$index], [[FieldCoordinateConverter::getCell($other['x']), $other['y']]])) {
                    $lost[] = $other['label'];
                }
            }
            if ($lost !== []) {
                $lostEntries[] = ['label' => $entry['label'], 'lost' => count($lost), 'example' => $lost[0]];
            }
        }

        return ['targets' => $lostTargets, 'entries' => $lostEntries, 'regions' => $this->findCutOffRegions($oldReach, $newReach)];
    }

    /**
     * @param list<array{x: int, y: int, label: string}> $entries
     * @return list<array{x: int, y: int, label: string}> One entry per old position.
     */
    private function removeDuplicateEntries(array $entries): array
    {
        $unique = [];
        foreach ($entries as $entry) {
            $unique[self::getKey($entry['x'], $entry['y'])] ??= $entry;
        }

        return array_values($unique);
    }

    /**
     * Walkable cells reachable from the seeds. A seed counts as reached even
     * when it is not walkable, since the player is placed there and can step
     * off it, as the engine only checks the ground being stepped onto.
     *
     * @param list<list<int>> $grid Collision by row and column.
     * @param list<array{int, int}> $seeds
     * @return array<string, true>
     */
    private function flood(array $grid, array $seeds): array
    {
        $reached = [];
        $queue = [];

        foreach ($seeds as [$x, $y]) {
            if (isset($grid[$y][$x])) {
                $reached[self::getKey($x, $y)] = true;
                $queue[] = [$x, $y];
            }
        }

        while ($queue !== []) {
            [$x, $y] = array_pop($queue);
            foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                $nextX = $x + $dx;
                $nextY = $y + $dy;
                $key = self::getKey($nextX, $nextY);
                if (! isset($reached[$key]) && MapCollisionComparison::isWalkable($grid[$nextY][$nextX] ?? null)) {
                    $reached[$key] = true;
                    $queue[] = [$nextX, $nextY];
                }
            }
        }

        return $reached;
    }

    /**
     * @param array<string, true> $reach
     * @param list<array{int, int}> $positions
     */
    private function isReached(array $reach, array $positions): bool
    {
        foreach ($positions as [$x, $y]) {
            foreach ([[0, 0], [1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                if (isset($reach[self::getKey($x + $dx, $y + $dy)])) {
                    return true;
                }
            }
        }

        return false;
    }

    /**
     * Walkable cells that are no longer reachable although a column they
     * cover was, grouped into connected regions.
     *
     * @param array<string, true> $oldReach
     * @param array<string, true> $newReach
     * @return list<array{size: int, cell: array{int, int}, choke: array{int, int}|null}>
     */
    private function findCutOffRegions(array $oldReach, array $newReach): array
    {
        $candidates = [];

        foreach ($this->collision->cells as $y => $row) {
            foreach ($row as $x => $kind) {
                $column = $x * MapCell::COLUMNS;
                if (MapCollisionComparison::isWalkable($kind) && ! isset($newReach[self::getKey($x, $y)])
                    && (isset($oldReach[self::getKey($column, $y)]) || isset($oldReach[self::getKey($column + 1, $y)]))) {
                    $candidates[self::getKey($x, $y)] = [$x, $y];
                }
            }
        }

        $regions = [];

        while ($candidates !== []) {
            $start = reset($candidates);
            unset($candidates[self::getKey(...$start)]);
            $region = [$start];
            $queue = [$start];

            while ($queue !== []) {
                [$x, $y] = array_pop($queue);
                foreach ([[1, 0], [-1, 0], [0, 1], [0, -1]] as [$dx, $dy]) {
                    $key = self::getKey($x + $dx, $y + $dy);
                    if (isset($candidates[$key])) {
                        $region[] = $candidates[$key];
                        $queue[] = $candidates[$key];
                        unset($candidates[$key]);
                    }
                }
            }

            $regions[] = ['size' => count($region), 'cell' => $start, 'choke' => $this->findChokeCell($region, $newReach)];
        }

        return $regions;
    }

    /**
     * The newly solid cell that most likely cut a region off: one bordering
     * both the region and the ground still reachable, else one bordering the
     * region.
     *
     * @param list<array{int, int}> $region
     * @param array<string, true> $newReach
     * @return array{int, int}|null
     */
    private function findChokeCell(array $region, array $newReach): ?array
    {
        $fallback = null;
        $members = [];
        foreach ($region as [$x, $y]) {
            $members[self::getKey($x, $y)] = true;
        }

        foreach ($this->collision->cells as $y => $row) {
            foreach ($row as $x => $kind) {
                if (! $this->collision->isNewlySolidCell($x, $y)) {
                    continue;
                }
                $bordersRegion = $this->isReached($members, [[$x, $y]]);
                if ($bordersRegion && $this->isReached($newReach, [[$x, $y]])) {
                    return [$x, $y];
                }
                $fallback ??= $bordersRegion ? [$x, $y] : null;
            }
        }

        return $fallback;
    }

    private static function getKey(int $x, int $y): string
    {
        return $x . ':' . $y;
    }
}
