<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\Steps;

use Ichiloto\Console\Support\SaveCompatibilityMetadata;
use Ichiloto\Console\Upgrade\ProjectConfigFile;
use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\ProjectUpgradeFollowUp;
use Ichiloto\Console\Upgrade\ProjectUpgradePlan;
use Ichiloto\Console\Upgrade\ProjectUpgradeReport;
use Ichiloto\Console\Upgrade\ProjectUpgradeStepInterface;
use Ichiloto\Engine\Core\ProjectFormat;
use RuntimeException;

/**
 * Format 1: a stable project id in ichiloto.json and a save compatibility
 * manifest, for projects created before versioned saves. Metadata that
 * already exists is kept exactly as it is.
 */
final class SaveMetadataStep implements ProjectUpgradeStepInterface
{
    public const int VERSION = 1;
    public const string MANIFEST_PATH = 'assets/Data/save-compatibility.php';

    public function getTargetVersion(): int
    {
        return self::VERSION;
    }

    public function createPlan(ProjectUpgradeContext $context): ProjectUpgradePlan
    {
        $configPath = $context->getPath(ProjectUpgradeContext::CONFIG_FILENAME);
        $config = ProjectConfigFile::readSettings($configPath);
        $existingId = trim(is_string($config['id'] ?? null) ? $config['id'] : '');
        $requestedId = trim((string) $context->requestedId);

        if ($existingId !== '' && $requestedId !== '' && $requestedId !== $existingId) {
            throw new RuntimeException(sprintf(
                'The project already has save identity "%s"; it cannot be replaced with "%s".',
                $existingId,
                $requestedId,
            ));
        }

        $projectId = $existingId !== ''
            ? $existingId
            : ($requestedId !== '' ? $this->assertProjectId($requestedId) : $this->deriveProjectId($context->root, $config));
        $manifestPath = $context->getPath(self::MANIFEST_PATH);

        if (file_exists($manifestPath) && ! is_file($manifestPath)) {
            throw new RuntimeException("The save compatibility manifest path is not a file: {$manifestPath}");
        }

        $changes = [];
        $writes = [];

        if ($existingId === '') {
            $changes[] = sprintf('Add stable project id %s to ichiloto.json.', $projectId);
            $writes[$configPath] = json_encode(
                ['id' => $projectId] + $config,
                JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
            ) . PHP_EOL;
        }

        if (! is_file($manifestPath)) {
            $changes[] = sprintf('Create %s at legacy content version 0.', self::MANIFEST_PATH);
            $writes[$manifestPath] = SaveCompatibilityMetadata::renderBaseline();
        }

        if ($changes === []) {
            $changes[] = 'Save metadata is already present; nothing changes.';
        }

        return new ProjectUpgradePlan(
            self::VERSION,
            ProjectFormat::CHANGES[self::VERSION],
            $changes,
            $writes,
            [new ProjectUpgradeFollowUp('Save identity', sprintf('Keep project id %s unchanged once saves exist.', $projectId))],
        );
    }

    public function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): ProjectUpgradeReport
    {
        return ProjectUpgradeReport::applyPlan($context, $plan);
    }

    /** @param array<string, mixed> $config */
    private function deriveProjectId(string $projectRoot, array $config): string
    {
        $composerPath = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';

        if (is_file($composerPath)) {
            try {
                $composer = ProjectConfigFile::readSettings($composerPath);
                $composerName = trim(is_string($composer['name'] ?? null) ? $composer['name'] : '');

                if ($composerName !== '' && $this->isCanonicalProjectId($composerName)) {
                    return $composerName;
                }
            } catch (RuntimeException) {
                // A legacy project's unrelated Composer problem should not
                // prevent deriving an identity from its Ichiloto metadata.
            }
        }

        $projectName = trim(is_string($config['name'] ?? null) ? $config['name'] : '');
        $slug = $this->slugify($projectName !== '' ? $projectName : basename($projectRoot));

        if ($slug === '') {
            throw new RuntimeException('A stable project id could not be derived. Pass one with --id=vendor/project.');
        }

        return 'ichiloto/' . $slug;
    }

    private function assertProjectId(string $projectId): string
    {
        if (! $this->isCanonicalProjectId($projectId)) {
            throw new RuntimeException('The project id must use stable lowercase vendor/project notation.');
        }

        return $projectId;
    }

    private function isCanonicalProjectId(string $projectId): bool
    {
        return preg_match('/^[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?\/[a-z0-9](?:[a-z0-9._-]*[a-z0-9])?$/', $projectId) === 1;
    }

    private function slugify(string $value): string
    {
        $slug = preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)) ?? '';

        return strtolower(trim($slug, '-'));
    }
}
