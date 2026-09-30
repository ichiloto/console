<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

/**
 * One numbered step of the project format chain. A step converts a project
 * from the previous format to its target format. A later format change adds a
 * step and never edits an earlier one, so every project reaches the current
 * format through the same sequence.
 */
interface ProjectUpgradeStepInterface
{
    /** The format version this step produces. */
    public function getTargetVersion(): int;

    /**
     * Reads the project and describes, without writing, what the step will
     * change and what will need a person afterwards.
     */
    public function createPlan(ProjectUpgradeContext $context): ProjectUpgradePlan;

    /** Writes a plan created for the project as it is now. */
    public function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): ProjectUpgradeReport;
}
