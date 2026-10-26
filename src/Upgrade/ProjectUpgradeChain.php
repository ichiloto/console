<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

use Ichiloto\Console\Upgrade\Steps\MasterySpendStep;
use Ichiloto\Console\Upgrade\Steps\MasteryTreeStep;
use Ichiloto\Console\Upgrade\Steps\SaveMetadataStep;
use Ichiloto\Console\Upgrade\Steps\SkitBackdropStep;
use InvalidArgumentException;

/**
 * The project format chain: every numbered step this Console carries, in
 * order, like the save compatibility chain. The Engine that reads a project
 * names the format to reach; the chain runs the steps from the project's
 * recorded format up to it. The new version is recorded after each step
 * succeeds, so an interrupted upgrade resumes at the step that did not finish.
 */
final readonly class ProjectUpgradeChain
{
    /** @var array<int, ProjectUpgradeStepInterface> Steps by target version. */
    private array $steps;
    /** The highest format this chain can upgrade a project to. */
    public int $latestVersion;

    /** @param list<ProjectUpgradeStepInterface> $steps One step per version, from 1 up. */
    public function __construct(array $steps)
    {
        $byVersion = [];

        foreach ($steps as $step) {
            $byVersion[$step->getTargetVersion()] = $step;
        }

        ksort($byVersion);

        if ($byVersion === [] || count($byVersion) !== count($steps) || array_keys($byVersion) !== range(1, count($byVersion))) {
            throw new InvalidArgumentException('The upgrade chain needs exactly one step for each format from 1 up.');
        }

        $this->steps = $byVersion;
        $this->latestVersion = array_key_last($byVersion);
    }

    /** The registered steps, in order. A new format adds its step here. */
    public static function createDefault(): self
    {
        return new self([
            new SaveMetadataStep(),
            new MasterySpendStep(),
            new MasteryTreeStep(),
            new SkitBackdropStep(),
        ]);
    }

    public function getRecordedVersion(ProjectUpgradeContext $context): int
    {
        return ProjectConfigFile::getFormatVersion($context->getPath(ProjectUpgradeContext::CONFIG_FILENAME));
    }

    /**
     * The steps that bring a project from one format to another.
     *
     * @return list<ProjectUpgradeStepInterface>
     */
    public function getPendingSteps(int $version, int $targetVersion): array
    {
        if ($targetVersion > $this->latestVersion) {
            throw new InvalidArgumentException(sprintf('This Console has upgrade steps up to format %d, not %d.', $this->latestVersion, $targetVersion));
        }

        return array_values(array_filter(
            $this->steps,
            static fn(ProjectUpgradeStepInterface $step): bool => $step->getTargetVersion() > $version
                && $step->getTargetVersion() <= $targetVersion,
        ));
    }

    /**
     * Plans every step up to the target format against the project as it
     * is now, for the person to read before anything is written.
     *
     * @return list<ProjectUpgradePlan>
     */
    public function createPlans(ProjectUpgradeContext $context, int $targetVersion): array
    {
        return array_map(
            static fn(ProjectUpgradeStepInterface $step): ProjectUpgradePlan => $step->createPlan($context),
            $this->getPendingSteps($this->getRecordedVersion($context), $targetVersion),
        );
    }

    /**
     * Runs every step up to the target format. Each is planned again just
     * before it is applied, so it reads what the previous steps wrote.
     *
     * @return list<ProjectUpgradeReport>
     */
    public function runPendingSteps(ProjectUpgradeContext $context, int $targetVersion): array
    {
        $reports = [];
        $configPath = $context->getPath(ProjectUpgradeContext::CONFIG_FILENAME);

        foreach ($this->getPendingSteps($this->getRecordedVersion($context), $targetVersion) as $step) {
            $reports[] = $step->applyPlan($context, $step->createPlan($context));
            ProjectConfigFile::setFormatVersion($configPath, $step->getTargetVersion());
        }

        return $reports;
    }
}
