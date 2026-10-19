<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

/**
 * What one step will do to a project: a line per pending change for the
 * person to read, the file contents it will write, and the follow-up items
 * its report will carry. Nothing is written until the plan is applied.
 */
final readonly class ProjectUpgradePlan
{
    /**
     * @param int $targetVersion The format the step produces.
     * @param string $title What the format introduces.
     * @param list<string> $changes One human line per pending change, with counts.
     * @param array<string, string> $writes New contents by absolute path.
     * @param list<ProjectUpgradeFollowUp> $followUps Items for a person.
     */
    public function __construct(
        public int $targetVersion,
        public string $title,
        public array $changes,
        public array $writes = [],
        public array $followUps = [],
    ) {
    }
}
