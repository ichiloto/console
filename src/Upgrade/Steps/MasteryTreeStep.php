<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\Steps;

use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\ProjectUpgradeFollowUp;
use Ichiloto\Console\Upgrade\ProjectUpgradePlan;
use Ichiloto\Console\Upgrade\ProjectUpgradeReport;
use Ichiloto\Console\Upgrade\ProjectUpgradeStepInterface;
use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;
use Ichiloto\Engine\Progression\Mastery\MasteryNodeKind;
use RuntimeException;
use Throwable;

/**
 * Format 3: a mastery tree every character shares. Each map node used to
 * name its skill and region in the character's own map; the tree now owns
 * them. Every node the maps teach is added to its region's trunk in
 * `mastery.php`, as an ungraded application under the same id, and the maps
 * keep only their own terms (price, level, requirements, place), in each
 * file's own source. Node ids do not change, so saves need no migration.
 *
 * Two maps that teach one skill under different ids, or one id for two
 * skills or regions, cannot share one tree without renaming a saved id; the
 * plan refuses them and names them, rather than choosing for the author.
 */
final class MasteryTreeStep implements ProjectUpgradeStepInterface
{
    public const int VERSION = 3;

    public function getTargetVersion(): int
    {
        return self::VERSION;
    }

    public function createPlan(ProjectUpgradeContext $context): ProjectUpgradePlan
    {
        $paths = glob($context->getPath('assets/' . MasteryCatalog::DIRECTORY . '/*.php')) ?: [];
        sort($paths, SORT_STRING);
        $maps = [];
        foreach ($paths as $path) {
            $maps[$path] = self::evaluate($context, $path);
        }
        $named = [];
        foreach ($maps as $path => $map) {
            foreach (is_array($map['nodes'] ?? null) ? $map['nodes'] : [] as $node) {
                if (is_array($node) && (array_key_exists('skill', $node) || array_key_exists('region', $node))) {
                    $named[] = ['path' => $path, 'id' => strval($node['id'] ?? ''), 'skill' => strval($node['skill'] ?? ''),
                        'region' => strval($node['region'] ?? '')];
                }
            }
        }
        if ($named === []) {
            return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION], [$paths === []
                ? 'The project has no Mastery Maps; nothing changes.'
                : 'The Mastery Maps name no skills or regions of their own; nothing changes.']);
        }

        $settingsPath = $context->getPath('assets/' . MasteryCatalog::SETTINGS);
        if (! is_file($settingsPath)) {
            throw new RuntimeException(sprintf('The Mastery Maps name regions, but %s, which declares them, is missing.',
                $context->getRelativePath($settingsPath)));
        }
        $settings = self::evaluate($context, $settingsPath);
        $regions = is_array($settings['regions'] ?? null) ? $settings['regions'] : [];
        $regionIndexes = [];
        foreach ($regions as $index => $region) {
            $regionIndexes[strval(is_array($region) ? $region['id'] ?? '' : '')] = $index;
        }

        $trunk = self::listTreeNodes($settings);
        $added = [];
        $conflicts = [];
        foreach ($named as $node) {
            $where = sprintf('%s node %s', $context->getRelativePath($node['path']), $node['id']);
            if (! isset($regionIndexes[$node['region']])) {
                $conflicts[] = sprintf('%s sits in region "%s", which %s does not declare.', $where, $node['region'], MasteryCatalog::SETTINGS);
                continue;
            }
            $known = $trunk[$node['id']] ?? null;
            if ($known !== null && ($known['skill'] !== $node['skill'] || $known['region'] !== $node['region'])) {
                $conflicts[] = sprintf('%s teaches %s in %s, but node %s already teaches %s in %s.', $where, $node['skill'], $node['region'],
                    $node['id'], $known['skill'], $known['region']);
                continue;
            }
            $teacher = array_find_key($trunk, static fn(array $other): bool => $other['skill'] === $node['skill']);
            if ($teacher !== null && $teacher !== $node['id']) {
                $conflicts[] = sprintf('%s teaches %s, which node %s already teaches.', $where, $node['skill'], $teacher);
                continue;
            }
            if ($known === null) {
                $trunk[$node['id']] = ['skill' => $node['skill'], 'region' => $node['region']];
                $added[$node['region']][] = ['id' => $node['id'], 'kind' => MasteryNodeKind::APPLICATION->value, 'skill' => $node['skill']];
            }
        }
        if ($conflicts !== []) {
            throw new RuntimeException("The Mastery Maps cannot share one tree without renaming node ids that saves record:\n- "
                . implode("\n- ", $conflicts) . "\nGive each skill one node id across the maps, with a content migration for the ids renamed, then upgrade again.");
        }

        $upgradedSettings = $settings;
        foreach ($added as $region => $nodes) {
            $index = $regionIndexes[$region];
            $upgradedSettings['regions'][$index]['nodes'] = [...(is_array($regions[$index]['nodes'] ?? null) ? $regions[$index]['nodes'] : []), ...$nodes];
        }
        $writes = [$settingsPath => self::rewrite($settingsPath, $settings, $upgradedSettings)];
        $changes = [sprintf('Add %d node%s to the region trunks of %s, each teaching the skill the maps named, under the same id.',
            array_sum(array_map('count', $added)), array_sum(array_map('count', $added)) === 1 ? '' : 's', $context->getRelativePath($settingsPath))];
        foreach ($maps as $path => $map) {
            $nodes = is_array($map['nodes'] ?? null) ? $map['nodes'] : [];
            $upgraded = $map;
            $upgraded['nodes'] = array_map(static function (mixed $node): mixed {
                if (is_array($node)) {
                    unset($node['skill'], $node['region']);
                }

                return $node;
            }, $nodes);
            if ($upgraded === $map) {
                continue;
            }
            $writes[$path] = self::rewrite($path, $map, $upgraded);
            $changes[] = sprintf('Keep only %s\'s own terms in %s: remove each node\'s skill and region.', strval($map['actor'] ?? ''),
                $context->getRelativePath($path));
        }

        return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION], $changes, $writes, [
            new ProjectUpgradeFollowUp('Mastery', 'Every character now reaches the whole mastery tree. The nodes the maps taught sit on '
                . 'their region\'s trunk: place them on branches with their kind and grade (Database > Mastery Branches), and price on the '
                . 'tree any node characters without their own terms should be able to learn.'),
        ]);
    }

    public function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): ProjectUpgradeReport
    {
        return ProjectUpgradeReport::applyPlan($context, $plan);
    }

    /**
     * The nodes the tree already holds, on trunks and branches.
     *
     * @param array<array-key, mixed> $settings
     * @return array<string, array{skill: string, region: string}>
     */
    private static function listTreeNodes(array $settings): array
    {
        $nodes = [];
        foreach (is_array($settings['regions'] ?? null) ? $settings['regions'] : [] as $region) {
            foreach (is_array($region) && is_array($region['nodes'] ?? null) ? $region['nodes'] : [] as $node) {
                $nodes[strval($node['id'] ?? '')] = ['skill' => strval($node['skill'] ?? ''), 'region' => strval($region['id'] ?? '')];
            }
        }
        foreach (is_array($settings['branches'] ?? null) ? $settings['branches'] : [] as $branch) {
            foreach (is_array($branch) && is_array($branch['nodes'] ?? null) ? $branch['nodes'] : [] as $node) {
                $nodes[strval($node['id'] ?? '')] = ['skill' => strval($node['skill'] ?? ''), 'region' => strval($branch['region'] ?? '')];
            }
        }

        return $nodes;
    }

    /** @return array<array-key, mixed> */
    private static function evaluate(ProjectUpgradeContext $context, string $path): array
    {
        try {
            $data = PhpDataFile::evaluateIsolated($path, $context->root);
        } catch (Throwable $error) {
            throw new RuntimeException(sprintf('%s could not be read: %s', $context->getRelativePath($path), $error->getMessage()), previous: $error);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $old
     * @param array<array-key, mixed> $new
     */
    private static function rewrite(string $path, array $old, array $new): string
    {
        return ArraySourceWriter::rewrite(PhpArraySourceDocument::parse((string) file_get_contents($path)), $old, $new)->source;
    }
}
