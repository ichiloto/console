<?php

declare(strict_types=1);

use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Console\Support\GuiEditorLocator;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * `ichiloto edit --gui` finds the native editor without building or
 * downloading it: the executable ICHILOTO_GUI_EDITOR names, else a release
 * then a debug build in the gui-editor checkout beside the console, and on
 * macOS first the checkout's Ichiloto.app around the release build.
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
    $locator = new GuiEditorLocator($console, platform: 'Linux');
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

    // On macOS the bundle, whose executable links to the release build, names the editor Ichiloto.
    $bundle = $workspace . '/gui-editor/' . GuiEditorLocator::MACOS_BUNDLE_EXECUTABLE;
    mkdir(dirname($bundle), 0777, true);
    symlink('../../../../../target/release/' . GuiEditorLocator::BINARY, $bundle);
    assertGuiLaunch((new GuiEditorLocator($console, platform: 'Darwin'))->locate() === $bundle, 'on macOS the application bundle is preferred');
    assertGuiLaunch($locator->locate() === $release, 'elsewhere the bundle is not a candidate');
    unlink($release);
    assertGuiLaunch((new GuiEditorLocator($console, platform: 'Darwin'))->locate() === $debug, 'a bundle without a release build to run is passed over');
} finally {
    foreach ([$release, $debug] as $file) {
        @unlink($file);
    }
    exec('rm -rf ' . escapeshellarg($workspace));
}

// Both editors playtest through the console that opened them, unless the author named another.
putenv(EditorProjectBootstrap::CONSOLE_BINARY_ENVIRONMENT);
EditorProjectBootstrap::nameConsoleBinary();
assertGuiLaunch(getenv(EditorProjectBootstrap::CONSOLE_BINARY_ENVIRONMENT) === EditorProjectBootstrap::getConsoleBinary(), 'the editor is told which console opened it');
assertGuiLaunch(is_file(EditorProjectBootstrap::getConsoleBinary()), 'the named console exists');
putenv(EditorProjectBootstrap::CONSOLE_BINARY_ENVIRONMENT . '=/elsewhere/ichiloto');
EditorProjectBootstrap::nameConsoleBinary();
assertGuiLaunch(getenv(EditorProjectBootstrap::CONSOLE_BINARY_ENVIRONMENT) === '/elsewhere/ichiloto', 'a console the author named is kept');
putenv(EditorProjectBootstrap::CONSOLE_BINARY_ENVIRONMENT);

echo "gui-editor-launch: ok\n";
