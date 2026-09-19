<?php

declare(strict_types=1);

use Ichiloto\Console\Support\RendererPackageInstaller;

require dirname(__DIR__) . '/vendor/autoload.php';

final class RendererInstallTestFailure extends RuntimeException
{
}

function failRendererInstallTest(string $message): never
{
    throw new RendererInstallTestFailure($message);
}

function assertRendererInstall(bool $condition, string $message): void
{
    if (! $condition) {
        failRendererInstallTest($message);
    }
}

function makeDirectory(string $path): void
{
    if (! is_dir($path) && ! mkdir($path, 0755, true)) {
        failRendererInstallTest("Could not create test directory {$path}.");
    }
}

function removeTestTree(string $path): void
{
    if (! file_exists($path)) {
        return;
    }

    if (! is_dir($path) || is_link($path)) {
        @unlink($path);

        return;
    }

    $iterator = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($path, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );

    foreach ($iterator as $item) {
        $item->isDir() && ! $item->isLink() ? @rmdir($item->getPathname()) : @unlink($item->getPathname());
    }

    @rmdir($path);
}

/** @return array{root: string, descriptorFile: string, executableRelative: string} */
function writeTestPackage(string $root, string $renderer, string $platform, string $executableBody = "#!/bin/sh\nexit 0\n"): array
{
    removeTestTree($root);
    $executableRelative = "{$renderer}/{$platform}/{$renderer}-renderer";
    $extraRelative = "{$renderer}/{$platform}/resources/notes.txt";
    makeDirectory(dirname("{$root}/{$executableRelative}"));
    makeDirectory(dirname("{$root}/{$extraRelative}"));
    file_put_contents("{$root}/{$executableRelative}", $executableBody);
    file_put_contents("{$root}/{$extraRelative}", "payload resource\n");

    $descriptor = [
        'version' => 1,
        'renderer' => $renderer,
        'displayName' => strtoupper($renderer),
        'platform' => $platform,
        'packageVersion' => '9.9.9-test',
        'executable' => $executableRelative,
        'files' => [
            $executableRelative => hash_file('sha256', "{$root}/{$executableRelative}"),
            $extraRelative => hash_file('sha256', "{$root}/{$extraRelative}"),
        ],
    ];
    file_put_contents(
        "{$root}/renderer-package.json",
        json_encode($descriptor, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES),
    );

    return [
        'root' => $root,
        'descriptorFile' => "{$root}/renderer-package.json",
        'executableRelative' => $executableRelative,
    ];
}

/** @return array<string, mixed> */
function readManifest(string $boundary): array
{
    $manifestFile = "{$boundary}/installed/manifest.json";
    assertRendererInstall(is_file($manifestFile), 'manifest.json exists after installation.');
    $manifest = json_decode((string) file_get_contents($manifestFile), true);
    assertRendererInstall(is_array($manifest), 'manifest.json holds a JSON object.');

    return $manifest;
}

$workspace = sys_get_temp_dir() . '/ichiloto-renderer-install-test-' . getmypid();
removeTestTree($workspace);

try {
    $engine = "{$workspace}/engine";
    $boundary = "{$engine}/resources/renderers";
    makeDirectory($boundary);
    $installer = new RendererPackageInstaller();
    $hostPlatform = RendererPackageInstaller::hostPlatform();

    // 1. A directory package installs, sets the manifest and the executable bit.
    $package = writeTestPackage("{$workspace}/package", 'testgpu', $hostPlatform);
    $result = $installer->install($package['root'], $installer->resolveEngineBoundary($engine));
    assertRendererInstall($result['renderer'] === 'testgpu' && $result['fileCount'] === 2, 'install reports the package identity and file count.');
    $installedExecutable = "{$boundary}/installed/{$package['executableRelative']}";
    assertRendererInstall(is_file($installedExecutable) && is_executable($installedExecutable), 'the installed executable exists and is executable.');
    $manifest = readManifest($boundary);
    assertRendererInstall(
        ($manifest['version'] ?? null) === 1
            && ($manifest['renderers']['testgpu'][$hostPlatform] ?? null) === $package['executableRelative'],
        'the manifest maps the renderer and platform to the packaged executable.',
    );

    // 2. The Engine resolver accepts the installed layout when it is available.
    if (class_exists(Ichiloto\Engine\Rendering\Launch\PackagedRendererExecutableResolver::class)) {
        $resolved = new Ichiloto\Engine\Rendering\Launch\PackagedRendererExecutableResolver(
            "{$boundary}/installed/manifest.json",
            $hostPlatform,
        )->resolve('testgpu');
        assertRendererInstall(realpath($resolved) === realpath($installedExecutable), 'the Engine resolver resolves the installed executable.');
    }

    // 3. Reinstalling backs up the previous installation and replaces the payload.
    $replacement = writeTestPackage("{$workspace}/package-two", 'testgpu', $hostPlatform, "#!/bin/sh\nexit 7\n");
    $result = $installer->install($replacement['root'], $boundary);
    assertRendererInstall($result['backupDirectory'] !== null && is_dir($result['backupDirectory']), 'reinstalling backs up the previous payload.');
    assertRendererInstall(
        str_contains((string) file_get_contents($installedExecutable), 'exit 7'),
        'reinstalling replaces the executable payload.',
    );

    // 4. A second renderer joins the manifest without disturbing the first.
    $second = writeTestPackage("{$workspace}/package-doria", 'doria', $hostPlatform);
    $installer->install($second['root'], $boundary);
    $manifest = readManifest($boundary);
    assertRendererInstall(
        isset($manifest['renderers']['testgpu'][$hostPlatform], $manifest['renderers']['doria'][$hostPlatform]),
        'multiple renderers coexist in one manifest.',
    );

    // 5. A tampered payload is refused before anything is staged.
    $tampered = writeTestPackage("{$workspace}/package-tampered", 'tampered', $hostPlatform);
    file_put_contents("{$tampered['root']}/{$tampered['executableRelative']}", "#!/bin/sh\nexit 1\n");
    $refused = false;

    try {
        $installer->install($tampered['root'], $boundary);
    } catch (Throwable $error) {
        $refused = str_contains($error->getMessage(), 'SHA-256');
    }

    assertRendererInstall($refused, 'a hash mismatch refuses the installation.');
    assertRendererInstall(! isset(readManifest($boundary)['renderers']['tampered']), 'a refused package never reaches the manifest.');

    // 6. Unsafe descriptor paths are refused.
    $unsafe = writeTestPackage("{$workspace}/package-unsafe", 'unsafe', $hostPlatform);
    $descriptor = json_decode((string) file_get_contents($unsafe['descriptorFile']), true);
    $descriptor['files']['unsafe/' . $hostPlatform . '/../../escape'] = str_repeat('0', 64);
    file_put_contents($unsafe['descriptorFile'], json_encode($descriptor));
    $refused = false;

    try {
        $installer->install($unsafe['root'], $boundary);
    } catch (Throwable) {
        $refused = true;
    }

    assertRendererInstall($refused, 'a traversal path in the descriptor refuses the installation.');

    // 7. An unlisted payload file is refused.
    $smuggled = writeTestPackage("{$workspace}/package-smuggled", 'smuggled', $hostPlatform);
    file_put_contents("{$smuggled['root']}/smuggled/{$hostPlatform}/extra.bin", 'unlisted');
    $refused = false;

    try {
        $installer->install($smuggled['root'], $boundary);
    } catch (Throwable $error) {
        $refused = str_contains($error->getMessage(), 'unlisted');
    }

    assertRendererInstall($refused, 'an unlisted payload file refuses the installation.');

    // 8. A dry run verifies and reports without writing.
    $dry = writeTestPackage("{$workspace}/package-dry", 'dryrun', $hostPlatform);
    $result = $installer->install($dry['root'], $boundary, dryRun: true);
    assertRendererInstall($result['dryRun'] === true, 'the dry run reports itself.');
    assertRendererInstall(! is_dir("{$boundary}/installed/dryrun"), 'the dry run writes no payload.');
    assertRendererInstall(! isset(readManifest($boundary)['renderers']['dryrun']), 'the dry run writes no manifest entry.');

    // 9. A .tar.gz archive package installs identically.
    if (class_exists(PharData::class)) {
        $archiveSource = writeTestPackage("{$workspace}/package-archive", 'archived', $hostPlatform);
        $tarFile = "{$workspace}/archived.tar";
        $archive = new PharData($tarFile);
        $archive->buildFromDirectory($archiveSource['root']);
        $archive->compress(Phar::GZ);
        unset($archive);
        Phar::unlinkArchive($tarFile);
        $installer->install("{$tarFile}.gz", $boundary);
        assertRendererInstall(
            is_executable("{$boundary}/installed/{$archiveSource['executableRelative']}"),
            'a .tar.gz package installs its executable.',
        );
    }

    // 10. A project directory resolves to its installed Engine package.
    $project = "{$workspace}/project";
    makeDirectory("{$project}/vendor/ichiloto");
    symlink($engine, "{$project}/vendor/ichiloto/engine");
    assertRendererInstall(
        realpath($installer->resolveProjectBoundary($project)) === realpath($boundary),
        'a project resolves to its installed Engine renderer boundary.',
    );

    echo "renderer-install: all checks passed.\n";
} finally {
    removeTestTree($workspace);
}
