<?php

declare(strict_types=1);

use Ichiloto\Console\Support\GuiEditorLocator;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * `ichiloto edit --gui` finds the native editor without building or
 * downloading it: the executable ICHILOTO_GUI_EDITOR names, else a release
 * then a debug build in the gui-editor checkout beside the console.
 */
function assertGuiLaunch(bool $condition, string $message): void
{
    if (! $condition) {
        fwrite(STDERR, "gui-editor-launch: {$message}\n");
        exit(1);
    }
}

$workspace = sys_get_temp_dir() . '/ichiloto-gui-launch-' . uniqid();
$console = $workspace . '/console';
$release = $workspace . '/gui-editor/target/release/' . GuiEditorLocator::BINARY;
$debug = $workspace . '/gui-editor/target/debug/' . GuiEditorLocator::BINARY;
mkdir($console, 0777, true);
mkdir(dirname($release), 0777, true);
mkdir(dirname($debug), 0777, true);

try {
    $locator = new GuiEditorLocator($console);
    assertGuiLaunch($locator->locate() === null, 'nothing is found before anything is built');
    assertGuiLaunch(str_contains($locator->describeMissing(), 'cargo build --release'), 'the missing message says how to build it');

    file_put_contents($debug, "#!/bin/sh\n");
    chmod($debug, 0755);
    assertGuiLaunch($locator->locate() === $debug, 'a debug build is found');

    file_put_contents($release, "#!/bin/sh\n");
    chmod($release, 0755);
    assertGuiLaunch($locator->locate() === $release, 'a release build is preferred to a debug one');

    $configured = new GuiEditorLocator($console, $workspace . '/elsewhere');
    assertGuiLaunch($configured->locate() === null, 'a configured path that is not an executable is not replaced by a build');
    assertGuiLaunch(str_contains($configured->describeMissing(), GuiEditorLocator::ENVIRONMENT), 'the message names the variable');
    assertGuiLaunch((new GuiEditorLocator($console, $debug))->locate() === $debug, 'a configured executable wins');
} finally {
    foreach ([$release, $debug] as $file) {
        @unlink($file);
    }
    exec('rm -rf ' . escapeshellarg($workspace));
}

echo "gui-editor-launch: ok\n";
