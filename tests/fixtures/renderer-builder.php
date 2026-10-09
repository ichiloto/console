<?php

declare(strict_types=1);

// A windowless package builder for Console orchestration tests, never a native executable.
$options = getopt('', ['describe', 'out:']);
$source = __DIR__;
$out = $options['out'];
$platform = trim(file_get_contents($source . '/platform'));
$package = $out . '/package';
$fingerprint = hash_file('sha256', $source . '/input');
$mode = is_file($source . '/mode') ? trim(file_get_contents($source . '/mode')) : '';
if ($mode === 'hung-description') { sleep(10); }
if (isset($options['describe'])) {
    echo json_encode(['renderer' => 'gpui', 'platform' => $platform, 'profile' => 'release',
        'fingerprint' => $fingerprint, 'packageDirectory' => $package], JSON_THROW_ON_ERROR);
    exit;
}
file_put_contents($source . '/builds', "build\n", FILE_APPEND | LOCK_EX);
if ($mode === 'fail') { fwrite(STDERR, 'Deliberate build failure'); exit(1); }
if ($mode === 'hung-build') { sleep(10); }
if ($mode === 'changed') { file_put_contents($source . '/input', file_get_contents($source . '/input') . '-edited'); }
if ($mode === 'slow') { usleep(150000); }
$renderer = $mode === 'wrong-renderer' ? 'other' : 'gpui';
$target = $mode === 'wrong-platform' ? 'other-x64' : $platform;
$relative = $renderer . '/' . $target . '/renderer';
mkdir($package . '/' . dirname($relative), 0755, true);
file_put_contents($package . '/' . $relative, 'fake-optimized-renderer:' . $fingerprint);
file_put_contents($package . '/' . dirname($relative) . '/resource', 'bundle-resource');
$files = [$relative => hash_file('sha256', $package . '/' . $relative),
    dirname($relative) . '/resource' => hash_file('sha256', $package . '/' . dirname($relative) . '/resource')];
if ($mode === 'bad-hash') { $files[$relative] = str_repeat('0', 64); }
file_put_contents($package . '/renderer-package.json', json_encode(['version' => 1, 'renderer' => $renderer,
    'platform' => $target, 'packageVersion' => '0.1.0', 'executable' => $relative, 'files' => $files], JSON_THROW_ON_ERROR));
echo "Fake release package ready.\n";
