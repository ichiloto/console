<?php

declare(strict_types=1);

/**
 * Proves the development autoload cascade: a sibling checkout wins when it
 * exists, and the vendored release answers when it does not.
 *
 * This is Composer's own behaviour — the root package's autoload-dev paths
 * are consulted before a dependency's paths for the same namespace, and a
 * missing directory falls through — so this test guards the configuration,
 * not a custom loader. It passes on Andrew's workspace (siblings present)
 * and on a bare public clone (siblings absent) by asserting the branch the
 * environment is actually in.
 */

$root = dirname(__DIR__);

/**
 * Collapses `.` and `..` segments without touching the filesystem, so a
 * sibling path that does not exist on this machine still compares.
 */
$normalize = static function (string $path): string {
    $segments = [];

    foreach (explode('/', str_replace('\\', '/', $path)) as $segment) {
        if ($segment === '' || $segment === '.') {
            continue;
        }

        if ($segment === '..' && $segments !== [] && end($segments) !== '..') {
            array_pop($segments);

            continue;
        }

        $segments[] = $segment;
    }

    return '/' . implode('/', $segments);
};

/** @var Composer\Autoload\ClassLoader $loader */
$loader = require $root . '/vendor/autoload.php';

$cascades = [
    'Ichiloto\\Editor\\' => ['../editor/src', 'vendor/ichiloto/editor/src', Ichiloto\Editor\Editor::class],
    'Ichiloto\\Engine\\' => ['../engine/src', 'vendor/ichiloto/engine/src', Ichiloto\Engine\Core\WorldConditionEvaluator::class],
    'Amasiye\\Figlet\\' => ['../figlet/src/Figlet', 'vendor/amasiye/figlet/src/Figlet', Amasiye\Figlet\Figlet::class],
];

$prefixes = $loader->getPrefixesPsr4();

foreach ($cascades as $namespace => [$siblingRelative, $vendorRelative, $probeClass]) {
    $paths = $prefixes[$namespace] ?? null;

    if (! is_array($paths) || count($paths) < 2) {
        throw new RuntimeException(sprintf(
            '%s must cascade over at least the sibling and the vendored release; found %d path(s).',
            $namespace,
            is_array($paths) ? count($paths) : 0,
        ));
    }

    // The sibling is consulted first, whether or not it exists here.
    $first = $normalize($paths[0]);
    $expectedSibling = $normalize($root . '/' . $siblingRelative);

    if ($first !== $expectedSibling) {
        throw new RuntimeException(sprintf(
            '%s consults "%s" first; expected the sibling checkout "%s".',
            $namespace,
            $first,
            $expectedSibling,
        ));
    }

    // The branch this environment is in resolves accordingly.
    $resolved = (string) new ReflectionClass($probeClass)->getFileName();
    $expectedRoot = realpath(is_dir($expectedSibling) ? $expectedSibling : $root . '/' . $vendorRelative);

    if ($expectedRoot === false || ! str_starts_with($resolved, $expectedRoot)) {
        throw new RuntimeException(sprintf(
            '%s resolved %s from "%s"; expected it under "%s" (%s).',
            $namespace,
            $probeClass,
            $resolved,
            (string) $expectedRoot,
            is_dir($expectedSibling) ? 'sibling present' : 'sibling absent',
        ));
    }
}

fwrite(STDOUT, "Development autoload cascades: sibling first, vendored release as the fallback.\n");
