<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use JsonException;
use RuntimeException;

/** Adds mandatory save metadata to projects created before it was scaffolded. */
final class LegacyProjectUpgrader
{
    /**
     * @return array{
     *   projectRoot: string,
     *   projectId: string,
     *   configChanged: bool,
     *   manifestChanged: bool,
     *   dryRun: bool
     * }
     */
    public function upgrade(string $projectRoot, ?string $requestedId = null, bool $dryRun = false): array
    {
        $canonicalRoot = realpath($projectRoot);

        if (! is_string($canonicalRoot) || ! is_dir($canonicalRoot)) {
            throw new RuntimeException("The project directory does not exist: {$projectRoot}");
        }

        $configPath = $canonicalRoot . DIRECTORY_SEPARATOR . 'ichiloto.json';

        if (! is_file($configPath)) {
            throw new RuntimeException("ichiloto.json was not found in {$canonicalRoot}.");
        }

        $config = $this->readJsonObject($configPath, 'project configuration');
        $existingId = trim(is_string($config['id'] ?? null) ? $config['id'] : '');
        $requestedId = trim((string) $requestedId);

        if ($existingId !== '' && $requestedId !== '' && $requestedId !== $existingId) {
            throw new RuntimeException(sprintf(
                'The project already has save identity "%s"; it cannot be replaced with "%s".',
                $existingId,
                $requestedId,
            ));
        }

        $projectId = $existingId !== ''
            ? $existingId
            : ($requestedId !== '' ? $this->assertProjectId($requestedId) : $this->deriveProjectId($canonicalRoot, $config));
        $configChanged = $existingId === '';
        $manifestPath = $canonicalRoot . DIRECTORY_SEPARATOR . 'assets' . DIRECTORY_SEPARATOR . 'Data'
            . DIRECTORY_SEPARATOR . 'save-compatibility.php';

        if (file_exists($manifestPath) && ! is_file($manifestPath)) {
            throw new RuntimeException("The save compatibility manifest path is not a file: {$manifestPath}");
        }

        $manifestChanged = ! is_file($manifestPath);

        if (! $dryRun) {
            if ($configChanged) {
                $config = ['id' => $projectId] + $config;
                $this->writeAtomically(
                    $configPath,
                    json_encode(
                        $config,
                        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR,
                    ) . PHP_EOL,
                );
            }

            if ($manifestChanged) {
                $this->writeAtomically($manifestPath, SaveCompatibilityMetadata::renderBaseline());
            }
        }

        return [
            'projectRoot' => $canonicalRoot,
            'projectId' => $projectId,
            'configChanged' => $configChanged,
            'manifestChanged' => $manifestChanged,
            'dryRun' => $dryRun,
        ];
    }

    /** @param array<string, mixed> $config */
    private function deriveProjectId(string $projectRoot, array $config): string
    {
        $composerPath = $projectRoot . DIRECTORY_SEPARATOR . 'composer.json';

        if (is_file($composerPath)) {
            try {
                $composer = $this->readJsonObject($composerPath, 'Composer configuration');
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

    /** @return array<string, mixed> */
    private function readJsonObject(string $path, string $label): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Unable to parse the %s at %s: %s', $label, $path, $exception->getMessage()), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException(sprintf('The %s at %s must contain a JSON object.', $label, $path));
        }

        return $data;
    }

    private function writeAtomically(string $path, string $contents): void
    {
        $directory = dirname($path);
        $permissions = is_file($path)
            ? (fileperms($path) & 0777)
            : (0666 & ~umask());

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException("Unable to create {$directory}.");
        }

        $temporaryPath = tempnam($directory, '.ichiloto-upgrade-');

        if (! is_string($temporaryPath)) {
            throw new RuntimeException("Unable to prepare an atomic write for {$path}.");
        }

        try {
            if (file_put_contents($temporaryPath, $contents) === false
                || ! chmod($temporaryPath, $permissions)
                || ! rename($temporaryPath, $path)) {
                throw new RuntimeException("Unable to write {$path}.");
            }
        } finally {
            if (is_file($temporaryPath)) {
                unlink($temporaryPath);
            }
        }
    }
}
