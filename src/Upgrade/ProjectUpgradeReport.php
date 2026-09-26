<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

/** What one applied step changed and what it left for a person. */
final readonly class ProjectUpgradeReport
{
    /**
     * @param int $targetVersion The format the step produced.
     * @param string $title What the format introduces.
     * @param list<string> $changes The changes that were made.
     * @param list<string> $writtenPaths Project-relative paths the step wrote.
     * @param list<ProjectUpgradeFollowUp> $followUps Items for a person.
     */
    public function __construct(
        public int $targetVersion,
        public string $title,
        public array $changes,
        public array $writtenPaths,
        public array $followUps,
    ) {
    }

    /** Applies a plan's writes and returns the report it describes. */
    public static function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): self
    {
        $writer = new ProjectFileWriter();

        foreach ($plan->writes as $path => $contents) {
            $writer->writeAtomically($path, $contents);
        }

        return new self(
            $plan->targetVersion,
            $plan->title,
            $plan->changes,
            array_map($context->getRelativePath(...), array_keys($plan->writes)),
            $plan->followUps,
        );
    }
}
