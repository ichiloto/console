<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

use Ichiloto\Console\Upgrade\Steps\SaveMetadataStep;
use Ichiloto\Console\Upgrade\Steps\TwoColumnCellsStep;
use Ichiloto\Engine\Core\ProjectFormat;
use InvalidArgumentException;

/**
 * The project format chain: every numbered step from a project's recorded
 * format to the engine's, in order, like the save compatibility chain. The
 * new version is recorded after each step succeeds, so an interrupted
 * upgrade resumes at the step that did not finish.
 */
final readonly class ProjectUpgradeChain
{
    /** @var array<int, ProjectUpgradeStepInterface> Steps by target version. */
    private array $steps;
    /** The format the chain upgrades projects to. */
    public int $currentVersion;

    /**
     * @param list<ProjectUpgradeStepInterface> $steps One step per version, from 1 to the current format.
     * @param int $currentVersion The format the chain must reach.
     */
    public function __construct(array $steps, int $currentVersion)
    {
        $this->currentVersion = $currentVersion;
        $byVersion = [];

        foreach ($steps as $step) {
            $byVersion[$step->getTargetVersion()] = $step;
        }

        ksort($byVersion);

        if (array_keys($byVersion) !== range(1, $currentVersion)) {
            throw new InvalidArgumentException(sprintf('The upgrade chain needs exactly one step for each format from 1 to %d.', $currentVersion));
        }

        $this->steps = $byVersion;
    }

    /** The registered steps, in order. A new format adds its step here. */
    public static function createDefault(): self
    {
        return new self([
            new SaveMetadataStep(),
            new TwoColumnCellsStep(),
        ], ProjectFormat::CURRENT);
    }

    public function getRecordedVersion(ProjectUpgradeContext $context): int
    {
        return ProjectConfigFile::getFormatVersion($context->getPath(ProjectUpgradeContext::CONFIG_FILENAME));
    }

    /** @return list<ProjectUpgradeStepInterface> The steps a project at a version still needs. */
    public function getPendingSteps(int $version): array
    {
        return array_values(array_filter(
            $this->steps,
            static fn(ProjectUpgradeStepInterface $step): bool => $step->getTargetVersion() > $version,
        ));
    }

    /**
     * Plans every pending step against the project as it is now, for the
     * person to read before anything is written.
     *
     * @return list<ProjectUpgradePlan>
     */
    public function createPlans(ProjectUpgradeContext $context): array
    {
        return array_map(
            static fn(ProjectUpgradeStepInterface $step): ProjectUpgradePlan => $step->createPlan($context),
            $this->getPendingSteps($this->getRecordedVersion($context)),
        );
    }

    /**
     * Runs every pending step. Each is planned again just before it is
     * applied, so it reads what the previous steps wrote.
     *
     * @return list<ProjectUpgradeReport>
     */
    public function runPendingSteps(ProjectUpgradeContext $context): array
    {
        $reports = [];
        $configPath = $context->getPath(ProjectUpgradeContext::CONFIG_FILENAME);

        foreach ($this->getPendingSteps($this->getRecordedVersion($context)) as $step) {
            $reports[] = $step->applyPlan($context, $step->createPlan($context));
            ProjectConfigFile::setFormatVersion($configPath, $step->getTargetVersion());
        }

        return $reports;
    }
}
