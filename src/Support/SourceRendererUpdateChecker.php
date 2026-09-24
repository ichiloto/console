<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Closure;
use RuntimeException;

/** Checks a declared development renderer without creating files or invoking a compiler. */
final class SourceRendererUpdateChecker
{
    private const float DEFAULT_PROBE_TIMEOUT_SECONDS = 30;

    private readonly Closure $locateEngine;
    private readonly string $platform;

    /** @param callable(string): string|null $locateEngine Isolated project Engine resolution. */
    public function __construct(
        private readonly RendererPackageInstaller $installer = new RendererPackageInstaller(),
        private readonly RendererPreparationProcess $process = new RendererPreparationProcess(),
        ?callable $locateEngine = null,
        ?string $platform = null,
        private readonly float $probeTimeoutSeconds = self::DEFAULT_PROBE_TIMEOUT_SECONDS,
    ) {
        $this->locateEngine = $locateEngine === null ? $this->resolveEngine(...) : Closure::fromCallable($locateEngine);
        $this->platform = $platform ?? RendererPackageInstaller::hostPlatform();
    }

    public function check(string $projectDirectory, string $renderer): ?SourceRendererUpdate
    {
        if ($renderer === 'terminal') { return null; }
        if (preg_match('/^[a-z][a-z0-9-]*$/', $renderer) !== 1
            || preg_match('/^[a-z]+-[a-z0-9_]+$/', $this->platform) !== 1) {
            throw new RuntimeException('Invalid renderer or platform identity for update checking.');
        }
        $engine = ($this->locateEngine)($projectDirectory);
        $engine = realpath($engine) ?: $engine;
        $vendor = realpath($projectDirectory . '/vendor');
        // Composer --prefer-source is an installed dependency, not a developer workspace.
        if ($vendor !== false && ($engine === $vendor || str_starts_with($engine, $vendor . DIRECTORY_SEPARATOR))) { return null; }
        $declarationFile = $engine . '/resources/renderers/development.json';
        if (! file_exists($engine . '/.git') || ! is_file($declarationFile)) { return null; }
        $declaration = $this->readJson($declarationFile);
        if (($declaration['version'] ?? null) !== 1 || ! is_array($declaration['sources'] ?? null)) {
            throw new RuntimeException('Unsupported renderer development declaration: ' . $declarationFile);
        }
        $entry = $declaration['sources'][$renderer] ?? null;
        if ($entry === null) { return null; }
        if (! is_array($entry) || ! is_string($entry['directory'] ?? null) || ! is_string($entry['builder'] ?? null)) {
            throw new RuntimeException('Invalid renderer source declaration: ' . $declarationFile);
        }
        $source = realpath(dirname($declarationFile) . '/' . $entry['directory']);
        if ($source === false || ! is_dir($source) || ! file_exists($source . '/.git')) {
            throw new RuntimeException('The development renderer source declared in ' . $declarationFile . ' is unavailable.');
        }
        $builder = realpath($source . '/' . $entry['builder']);
        if ($builder === false || ! str_starts_with($builder, $source . DIRECTORY_SEPARATOR)
            || ! is_file($builder) || ! is_readable($builder)) {
            throw new RuntimeException('The declared renderer builder must be a readable file inside ' . $source);
        }
        $boundary = $this->installer->resolveEngineBoundary($engine);
        $description = $this->describe($source, $builder, $renderer, sys_get_temp_dir() . '/ichiloto-renderer-check-' . bin2hex(random_bytes(8)));
        $update = new SourceRendererUpdate($renderer, $this->platform, $source, $builder, $boundary,
            $description['fingerprint'], false, false);
        $current = $this->isCurrent($update);
        $skipped = ! $current && $this->matchesIdentity($update->getSkipFile(), $update->getIdentity());
        return new SourceRendererUpdate($renderer, $this->platform, $source, $builder, $boundary,
            $description['fingerprint'], $current, $skipped);
    }

    /** @return array{fingerprint: string, packageDirectory: string} */
    public function describe(string $source, string $builder, string $renderer, string $out): array
    {
        $description = json_decode($this->process->run([PHP_BINARY, $builder, '--describe', '--out=' . $out], $source,
            timeoutSeconds: $this->probeTimeoutSeconds), true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($description) || ($description['renderer'] ?? null) !== $renderer
            || ($description['platform'] ?? null) !== $this->platform || ($description['profile'] ?? null) !== 'release'
            || ! is_string($description['fingerprint'] ?? null) || preg_match('/^[a-f0-9]{64}$/', $description['fingerprint']) !== 1
            || ! is_string($description['packageDirectory'] ?? null)
            || ! str_starts_with($description['packageDirectory'], $out . DIRECTORY_SEPARATOR)
            || str_contains($description['packageDirectory'], "\0")
            || preg_match('~(?:^|[/\\\\])\.\.(?:[/\\\\]|$)~', $description['packageDirectory'])) {
            throw new RuntimeException('The renderer builder returned an invalid update description for ' . $renderer . '/' . $this->platform);
        }
        return ['fingerprint' => $description['fingerprint'], 'packageDirectory' => $description['packageDirectory']];
    }

    private function resolveEngine(string $project): string
    {
        $probe = <<<'PHP'
require $argv[1];
$file = (new ReflectionClass('Ichiloto\Engine\Core\Game'))->getFileName();
if ($file === false) { throw new RuntimeException('The project Engine has no source file.'); }
echo json_encode(dirname($file, 3), JSON_THROW_ON_ERROR);
PHP;
        $root = json_decode($this->process->run([PHP_BINARY, '-r', $probe, $project . '/vendor/autoload.php'], $project,
            timeoutSeconds: $this->probeTimeoutSeconds), true, flags: JSON_THROW_ON_ERROR);
        if (! is_string($root) || ! is_dir($root)) { throw new RuntimeException('Cannot resolve the Engine loaded by this project.'); }
        return realpath($root) ?: $root;
    }

    private function isCurrent(SourceRendererUpdate $update): bool
    {
        $installed = $update->boundary . '/installed';
        if (! $this->matchesIdentity($update->getReceiptFile(), $update->getIdentity())
            || ! is_file($installed . '/manifest.json') || is_link($installed . '/manifest.json')) { return false; }
        try {
            $receipt = $this->readJson($update->getReceiptFile());
            $manifest = $this->readJson($installed . '/manifest.json');
        } catch (\Throwable) { return false; }
        $executable = $receipt['executable'] ?? null;
        $files = $receipt['files'] ?? null;
        if (($manifest['version'] ?? null) !== 1 || ! is_string($executable) || ! is_array($files) || $files === []
            || ! isset($files[$executable]) || ($manifest['renderers'][$update->renderer][$update->platform] ?? null) !== $executable) { return false; }
        $prefix = $update->renderer . '/' . $update->platform . '/';
        foreach ($files as $relative => $hash) {
            if (! is_string($relative) || ! str_starts_with($relative, $prefix) || str_contains($relative, "\0")
                || str_contains($relative, '\\') || preg_match('~(?:^|/)\.\.(?:/|$)~', $relative)
                || ! is_string($hash) || preg_match('/^[a-f0-9]{64}$/', $hash) !== 1) { return false; }
            $path = realpath($installed . '/' . $relative);
            if ($path === false || ! str_starts_with($path, $installed . DIRECTORY_SEPARATOR)
                || ! is_file($path) || ! is_readable($path) || ! hash_equals($hash, (string) hash_file('sha256', $path))) { return false; }
        }
        $payload = $installed . '/' . rtrim($prefix, '/');
        if (! is_dir($payload)) { return false; }
        $entries = new \RecursiveIteratorIterator(new \RecursiveDirectoryIterator($payload, \FilesystemIterator::SKIP_DOTS), \RecursiveIteratorIterator::SELF_FIRST);
        foreach ($entries as $entry) {
            if ($entry->isLink()) { return false; }
            if ($entry->isFile() && ! array_key_exists(substr($entry->getPathname(), strlen($installed) + 1), $files)) { return false; }
        }
        return is_executable($installed . '/' . $executable);
    }

    /** @param array<string, int|string> $identity */
    private function matchesIdentity(string $file, array $identity): bool
    {
        if (! is_file($file) || is_link($file)) { return false; }
        try { $saved = $this->readJson($file); } catch (\Throwable) { return false; }
        foreach ($identity as $key => $value) {
            if (($saved[$key] ?? null) !== $value) { return false; }
        }
        return true;
    }

    /** @return array<string, mixed> */
    private function readJson(string $file): array
    {
        $contents = @file_get_contents($file);
        if ($contents === false) { throw new RuntimeException('Cannot read ' . $file); }
        $value = json_decode($contents, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($value)) { throw new RuntimeException('Expected a JSON object in ' . $file); }
        return $value;
    }
}
