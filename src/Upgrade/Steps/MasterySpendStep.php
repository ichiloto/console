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
use Ichiloto\Engine\Progression\Mastery\MasteryProgress;
use RuntimeException;
use Throwable;

/**
 * Format 2: Mastery Maps spend Mastery points (SP). A character no longer
 * studies one node in a default order, so each map's nodes are listed in
 * their old study order and lose the `order` key, in the map file's own
 * source. Costs stay as they are; the report asks for SP prices and for a
 * content migration that converts EXP progress in saves.
 */
final class MasterySpendStep implements ProjectUpgradeStepInterface
{
    public const int VERSION = 2;

    public function getTargetVersion(): int
    {
        return self::VERSION;
    }

    public function createPlan(ProjectUpgradeContext $context): ProjectUpgradePlan
    {
        $changes = [];
        $writes = [];
        $paths = glob($context->getPath('assets/' . MasteryCatalog::DIRECTORY . '/*.php')) ?: [];
        foreach ($paths as $path) {
            $source = (string) file_get_contents($path);
            try {
                $map = PhpDataFile::evaluateIsolated($path, $context->root);
            } catch (Throwable $error) {
                throw new RuntimeException(sprintf('%s could not be read: %s', $context->getRelativePath($path), $error->getMessage()), previous: $error);
            }
            $nodes = is_array($map) && is_array($map['nodes'] ?? null) ? $map['nodes'] : [];
            $ordered = array_values(array_filter($nodes, static fn(mixed $node): bool => is_array($node) && array_key_exists('order', $node)));
            if ($ordered === []) {
                continue;
            }
            $upgraded = $map;
            $upgraded['nodes'] = self::listInStudyOrder($nodes);
            $writes[$path] = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse($source), $map, $upgraded)->source;
            $changes[] = sprintf('List the %d nodes of %s in their study order and remove the order key from %d.',
                count($nodes), $context->getRelativePath($path), count($ordered));
        }

        if ($paths === []) {
            return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION],
                ['The project has no Mastery Maps; nothing changes.']);
        }
        if ($changes === []) {
            $changes[] = 'The Mastery Maps keep no study order; nothing changes.';
        }

        return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION], $changes, $writes, [
            new ProjectUpgradeFollowUp('Mastery', 'Node costs are now prices in Mastery points (SP), spent from a total that '
                . 'battles and quests award. The costs kept were EXP: set SP prices on the nodes (Database > Mastery Maps) and '
                . 'Reward SP on enemies and quests.'),
            new ProjectUpgradeFollowUp('Saves', sprintf('A save that holds Mastery progress in EXP (%s) does not load until a '
                . 'content migration in %s converts it to points.', implode(', ', MasteryProgress::EXPERIENCE_KEYS),
                SaveMetadataStep::MANIFEST_PATH)),
        ]);
    }

    public function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): ProjectUpgradeReport
    {
        return ProjectUpgradeReport::applyPlan($context, $plan);
    }

    /**
     * The nodes in the order the Engine used to study them, by `order` and
     * then file order, without the key.
     *
     * @param array<array-key, mixed> $nodes
     * @return list<mixed>
     */
    private static function listInStudyOrder(array $nodes): array
    {
        $indexed = array_map(null, array_keys(array_values($nodes)), array_values($nodes));
        usort($indexed, static fn(array $a, array $b): int =>
            [intval(is_array($a[1]) ? $a[1]['order'] ?? 0 : 0), $a[0]] <=> [intval(is_array($b[1]) ? $b[1]['order'] ?? 0 : 0), $b[0]]);

        return array_map(static function (array $entry): mixed {
            $node = $entry[1];
            if (is_array($node)) {
                unset($node['order']);
            }

            return $node;
        }, $indexed);
    }
}
