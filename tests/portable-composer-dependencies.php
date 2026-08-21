<?php

declare(strict_types=1);

$root = dirname(__DIR__);
$manifest = json_decode((string) file_get_contents($root . '/composer.json'), true, flags: JSON_THROW_ON_ERROR);
$lock = json_decode((string) file_get_contents($root . '/composer.lock'), true, flags: JSON_THROW_ON_ERROR);

if (($manifest['repositories'] ?? []) !== []) {
    throw new RuntimeException('Published dependencies must resolve through Composer\'s default repository.');
}

$lockedPackages = [];

foreach (array_merge($lock['packages'] ?? [], $lock['packages-dev'] ?? []) as $package) {
    $lockedPackages[$package['name']] = $package;
}

foreach (['amasiye/figlet', 'ichiloto/editor', 'ichiloto/engine'] as $packageName) {
    $package = $lockedPackages[$packageName] ?? null;

    if (! is_array($package)) {
        throw new RuntimeException(sprintf('%s is missing from composer.lock.', $packageName));
    }

    if (($package['source']['type'] ?? null) === 'path' || ($package['dist']['type'] ?? null) === 'path') {
        throw new RuntimeException(sprintf('%s is still locked to a local path.', $packageName));
    }

    $sourceUrl = $package['source']['url'] ?? null;
    $distUrl = $package['dist']['url'] ?? null;

    if (! is_string($sourceUrl) && ! is_string($distUrl)) {
        throw new RuntimeException(sprintf('%s has no remotely installable source or dist URL.', $packageName));
    }
}

fwrite(STDOUT, "Composer dependencies are portable.\n");
