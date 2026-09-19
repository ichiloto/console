<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use JsonException;
use PharData;
use RuntimeException;
use Throwable;

/**
 * Installs a verified renderer package into an Engine's internal renderer
 * boundary (resources/renderers/installed). The package declares its own
 * renderer and platform identities, so any renderer implementation installs
 * through the same path; nothing here is specific to one renderer.
 *
 * A renderer package is a directory or .tar.gz whose root contains
 * renderer-package.json and the payload tree exactly as it installs under
 * installed/ (rooted at <renderer>/<platform>/). Every payload file is
 * SHA-256 verified against the package descriptor before anything is staged.
 */
final readonly class RendererPackageInstaller
{
    private const int DESCRIPTOR_VERSION = 1;

    private const string DESCRIPTOR_FILE = 'renderer-package.json';

    private const int MANIFEST_VERSION = 1;

    /**
     * @return array{
     *   renderer: string,
     *   platform: string,
     *   hostPlatform: string,
     *   packageVersion: string,
     *   executable: string,
     *   fileCount: int,
     *   installedDirectory: string,
     *   backupDirectory: ?string,
     *   dryRun: bool,
     * }
     */
    public function install(string $packagePath, string $boundaryDirectory, bool $dryRun = false): array
    {
        $real = realpath($packagePath);

        if ($real === false) {
            throw new RuntimeException(sprintf('Renderer package %s does not exist.', $packagePath));
        }

        $temporary = null;

        if (! is_dir($real)) {
            if (! str_ends_with(strtolower($real), '.tar.gz')) {
                throw new RuntimeException('A renderer package is a directory or a .tar.gz archive.');
            }

            $temporary = sys_get_temp_dir() . '/ichiloto-renderer-package-' . bin2hex(random_bytes(8));
            $this->ensureDirectory($temporary);

            try {
                new PharData($real)->extractTo($temporary, overwrite: true);
            } catch (Throwable $error) {
                $this->removeTree($temporary);

                throw new RuntimeException('The renderer package archive could not be extracted: ' . $error->getMessage());
            }
        }

        try {
            return $this->installExtracted($temporary ?? $real, $boundaryDirectory, $dryRun);
        } finally {
            if ($temporary !== null) {
                $this->removeTree($temporary);
            }
        }
    }

    /**
     * Resolves the Engine renderer boundary for a project directory: the
     * project's installed Engine package. A path-repository symlink resolves
     * to the live Engine checkout, which is the intended target there.
     */
    public function resolveProjectBoundary(string $projectDirectory): string
    {
        $engine = $projectDirectory . '/vendor/ichiloto/engine';

        if (! is_dir($engine)) {
            throw new RuntimeException(sprintf(
                'No installed Engine package at %s. Run composer install, or pass --engine with an Engine checkout.',
                $engine,
            ));
        }

        return $this->resolveEngineBoundary($engine);
    }

    /** Resolves the renderer boundary inside an Engine checkout or package. */
    public function resolveEngineBoundary(string $engineDirectory): string
    {
        $real = realpath($engineDirectory);

        if ($real === false || ! is_dir($real . '/resources/renderers')) {
            throw new RuntimeException(sprintf(
                '%s is not an Engine package: resources/renderers is missing.',
                $engineDirectory,
            ));
        }

        return $real . '/resources/renderers';
    }

    /** Mirrors the Engine resolver's host platform identity. */
    public static function hostPlatform(): string
    {
        $architecture = match (strtolower(php_uname('m'))) {
            'arm64', 'aarch64' => 'arm64',
            'x86_64', 'amd64' => 'x64',
            default => strtolower(php_uname('m')),
        };

        return strtolower(PHP_OS_FAMILY) . '-' . $architecture;
    }

    /** @return array{renderer: string, platform: string, packageVersion: string, executable: string, files: array<string, string>} */
    private function readDescriptor(string $packageRoot): array
    {
        $descriptorFile = $packageRoot . '/' . self::DESCRIPTOR_FILE;

        if (! is_file($descriptorFile)) {
            throw new RuntimeException('The package has no ' . self::DESCRIPTOR_FILE . ' at its root.');
        }

        try {
            $descriptor = json_decode((string) file_get_contents($descriptorFile), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $error) {
            throw new RuntimeException('The package descriptor is not valid JSON: ' . $error->getMessage());
        }

        if (! is_array($descriptor) || ($descriptor['version'] ?? null) !== self::DESCRIPTOR_VERSION) {
            throw new RuntimeException('The package descriptor must declare version ' . self::DESCRIPTOR_VERSION . '.');
        }

        $renderer = $descriptor['renderer'] ?? null;
        $platform = $descriptor['platform'] ?? null;
        $executable = $descriptor['executable'] ?? null;
        $files = $descriptor['files'] ?? null;
        $packageVersion = $descriptor['packageVersion'] ?? '';

        if (! is_string($renderer) || preg_match('/^[a-z][a-z0-9-]*$/', $renderer) !== 1) {
            throw new RuntimeException('The package descriptor needs a lowercase renderer id.');
        }

        if (! is_string($platform) || preg_match('/^[a-z]+-[a-z0-9_]+$/', $platform) !== 1) {
            throw new RuntimeException('The package descriptor needs a platform id such as darwin-arm64 or linux-x64.');
        }

        if (! is_array($files) || $files === []) {
            throw new RuntimeException('The package descriptor lists no files.');
        }

        $prefix = $renderer . '/' . $platform . '/';

        foreach ($files as $path => $hash) {
            if (! is_string($path) || ! is_string($hash) || preg_match('/^[0-9a-f]{64}$/', $hash) !== 1) {
                throw new RuntimeException('Every package file needs a relative path and a SHA-256 hash.');
            }

            $this->assertSafeRelativePath($path);

            if (! str_starts_with($path, $prefix)) {
                throw new RuntimeException(sprintf(
                    'Package file %s is outside the package payload root %s.',
                    $path,
                    $prefix,
                ));
            }
        }

        if (! is_string($executable) || ! array_key_exists($executable, $files)) {
            throw new RuntimeException('The package executable must be one of the listed package files.');
        }

        return [
            'renderer' => $renderer,
            'platform' => $platform,
            'packageVersion' => is_string($packageVersion) ? $packageVersion : '',
            'executable' => $executable,
            'files' => $files,
        ];
    }

    private function assertSafeRelativePath(string $path): void
    {
        if ($path === '' || str_contains($path, "\0") || str_contains($path, '\\')
            || preg_match('~^(?:/|[a-zA-Z]:)|(?:^|/)\.\.(?:/|$)~', $path) === 1) {
            throw new RuntimeException(sprintf('Package file path %s is not a safe relative path.', $path));
        }
    }

    /** @param array<string, string> $files */
    private function verifyPayload(string $packageRoot, array $files): void
    {
        foreach ($files as $path => $hash) {
            $absolute = $packageRoot . '/' . $path;

            if (! is_file($absolute)) {
                throw new RuntimeException(sprintf('The package is missing listed file %s.', $path));
            }

            if (! hash_equals($hash, (string) hash_file('sha256', $absolute))) {
                throw new RuntimeException(sprintf('Package file %s fails SHA-256 verification.', $path));
            }
        }

        foreach ($this->walkFiles($packageRoot) as $relative) {
            if ($relative === self::DESCRIPTOR_FILE) {
                continue;
            }

            if (! array_key_exists($relative, $files)) {
                throw new RuntimeException(sprintf('The package contains unlisted file %s.', $relative));
            }
        }
    }

    /** @return array{renderer: string, platform: string, hostPlatform: string, packageVersion: string, executable: string, fileCount: int, installedDirectory: string, backupDirectory: ?string, dryRun: bool} */
    private function installExtracted(string $packageRoot, string $boundaryDirectory, bool $dryRun): array
    {
        $descriptor = $this->readDescriptor($packageRoot);
        $this->verifyPayload($packageRoot, $descriptor['files']);

        if (! is_dir($boundaryDirectory)) {
            throw new RuntimeException(sprintf('%s is not an Engine renderer boundary.', $boundaryDirectory));
        }

        $installedDirectory = $boundaryDirectory . '/installed';
        $payloadDirectory = $installedDirectory . '/' . $descriptor['renderer'] . '/' . $descriptor['platform'];
        $backupDirectory = is_dir($payloadDirectory)
            ? sprintf(
                '%s/.backups/%s-%s-%s',
                $installedDirectory,
                $descriptor['renderer'],
                $descriptor['platform'],
                gmdate('Ymd\THis\Z'),
            )
            : null;

        $result = [
            'renderer' => $descriptor['renderer'],
            'platform' => $descriptor['platform'],
            'hostPlatform' => self::hostPlatform(),
            'packageVersion' => $descriptor['packageVersion'],
            'executable' => $descriptor['executable'],
            'fileCount' => count($descriptor['files']),
            'installedDirectory' => $installedDirectory,
            'backupDirectory' => $backupDirectory,
            'dryRun' => $dryRun,
        ];

        if ($dryRun) {
            return $result;
        }

        $staging = $installedDirectory . '/.staging-' . getmypid();
        $this->removeTree($staging);

        try {
            foreach ($descriptor['files'] as $path => $hash) {
                $this->copyFile($packageRoot . '/' . $path, $staging . '/' . $path);
            }

            $stagedExecutable = $staging . '/' . $descriptor['executable'];

            if (! @chmod($stagedExecutable, 0755) || ! is_executable($stagedExecutable)) {
                throw new RuntimeException('The staged renderer executable could not be made executable.');
            }

            if ($backupDirectory !== null) {
                $this->ensureDirectory(dirname($backupDirectory));

                if (! @rename($payloadDirectory, $backupDirectory)) {
                    throw new RuntimeException(sprintf('The existing installation could not be backed up to %s.', $backupDirectory));
                }
            }

            $this->ensureDirectory(dirname($payloadDirectory));

            if (! @rename($staging . '/' . $descriptor['renderer'] . '/' . $descriptor['platform'], $payloadDirectory)) {
                if ($backupDirectory !== null) {
                    @rename($backupDirectory, $payloadDirectory);
                }

                throw new RuntimeException('The verified payload could not be moved into the installation directory.');
            }

            $this->writeManifest($installedDirectory, $descriptor['renderer'], $descriptor['platform'], $descriptor['executable']);
        } finally {
            $this->removeTree($staging);
        }

        return $result;
    }

    private function writeManifest(string $installedDirectory, string $renderer, string $platform, string $executable): void
    {
        $manifestFile = $installedDirectory . '/manifest.json';
        $manifest = ['version' => self::MANIFEST_VERSION, 'renderers' => []];

        if (is_file($manifestFile)) {
            try {
                $existing = json_decode((string) file_get_contents($manifestFile), true, flags: JSON_THROW_ON_ERROR);

                if (is_array($existing) && ($existing['version'] ?? null) === self::MANIFEST_VERSION
                    && is_array($existing['renderers'] ?? null)) {
                    $manifest = $existing;
                }
            } catch (JsonException) {
                // An unreadable manifest is replaced by a valid one below.
            }
        }

        $manifest['renderers'][$renderer][$platform] = $executable;
        ksort($manifest['renderers']);

        $encoded = json_encode($manifest, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . "\n";
        $temporary = $manifestFile . '.tmp-' . getmypid();

        if (@file_put_contents($temporary, $encoded) === false || ! @rename($temporary, $manifestFile)) {
            @unlink($temporary);

            throw new RuntimeException('The renderer manifest could not be written.');
        }
    }

    /** @return list<string> */
    private function walkFiles(string $root): array
    {
        $files = [];
        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($root, \FilesystemIterator::SKIP_DOTS),
        );

        foreach ($iterator as $item) {
            if ($item->isFile()) {
                $files[] = substr($item->getPathname(), strlen($root) + 1);
            }
        }

        sort($files);

        return $files;
    }

    private function copyFile(string $source, string $destination): void
    {
        $this->ensureDirectory(dirname($destination));

        if (! @copy($source, $destination)) {
            throw new RuntimeException(sprintf('Package file %s could not be staged.', $source));
        }
    }

    private function ensureDirectory(string $directory): void
    {
        if (! is_dir($directory) && ! @mkdir($directory, 0755, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Directory %s could not be created.', $directory));
        }
    }

    private function removeTree(string $path): void
    {
        if (! file_exists($path)) {
            return;
        }

        if (! is_dir($path) || is_link($path)) {
            @unlink($path);

            return;
        }

        $iterator = new \RecursiveIteratorIterator(
            new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS),
            \RecursiveIteratorIterator::CHILD_FIRST,
        );

        foreach ($iterator as $item) {
            $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
        }

        @rmdir($path);
    }
}
