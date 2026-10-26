<?php

declare(strict_types=1);

use Ichiloto\Console\Support\ConsoleInstallation;

require dirname(__DIR__) . '/vendor/autoload.php';

/*
 * A message that needs a newer Console names the update for the installation
 * that is running: a checkout of the Console, or the Composer project that
 * requires it (a global install on Windows included). The executable also
 * starts when Composer installs it as a package rather than a checkout.
 */

$failures = [];

// Composer's installed data on Windows: install paths relative to vendor\composer, with either separator.
$windowsHome = 'C:\\Users\\Andrew\\AppData\\Roaming\\Composer';
$windowsConsole = $windowsHome . '\\vendor\\ichiloto\\console';
$global = new ConsoleInstallation($windowsConsole, [[
    'root' => ['name' => '__root__', 'install_path' => $windowsHome . '\\vendor\\composer/../../'],
    'versions' => ['ichiloto/console' => ['install_path' => $windowsHome . '\\vendor\\composer/../ichiloto/console']],
]]);
$expected = "run `composer update ichiloto/console --with-all-dependencies` in {$windowsHome}";
if ($global->getUpdateInstruction() !== $expected) {
    $failures[] = 'a Windows global install was not told to update its Composer home: ' . $global->getUpdateInstruction();
}

$windowsCheckout = 'D:\\Games\\ichiloto\\console';
$checkout = new ConsoleInstallation($windowsCheckout, [
    ['root' => ['name' => 'last-legend/game', 'install_path' => 'D:\\Games\\last-legend\\vendor\\composer/../../'], 'versions' => []],
    ['root' => ['name' => 'ichiloto/console', 'install_path' => $windowsCheckout . '\\vendor\\composer/../../'],
        'versions' => ['ichiloto/console' => ['install_path' => $windowsCheckout . '\\vendor\\composer/../../']]],
]);
if ($checkout->getUpdateInstruction() !== "pull the latest Ichiloto Console into {$windowsCheckout}, then run `composer install` there") {
    $failures[] = 'a Windows checkout, loaded beside a project vendor, was not told to pull itself: ' . $checkout->getUpdateInstruction();
}

$unknown = new ConsoleInstallation('/opt/ichiloto/console', []);
if ($unknown->getUpdateInstruction() !== 'update the Ichiloto Console installed in /opt/ichiloto/console') {
    $failures[] = 'an unrecorded installation was not named by its directory: ' . $unknown->getUpdateInstruction();
}

// This checkout recognises itself from Composer's live data.
$self = ConsoleInstallation::detect()->getUpdateInstruction();
if (! str_starts_with($self, 'pull the latest Ichiloto Console into ' . realpath(dirname(__DIR__)))) {
    $failures[] = 'this checkout did not recognise itself: ' . $self;
}

// Installed as a package: the executable finds the autoloader of the project that installed it.
$home = sys_get_temp_dir() . DIRECTORY_SEPARATOR . 'ichiloto-console-install-' . bin2hex(random_bytes(8));
$packageBin = $home . '/vendor/ichiloto/console/bin';
mkdir($packageBin, 0777, true);
copy(dirname(__DIR__) . '/bin/ichiloto', $packageBin . '/ichiloto');
file_put_contents($home . '/vendor/autoload.php', "<?php\nrequire " . var_export(dirname(__DIR__) . '/vendor/autoload.php', true) . ";\n");

try {
    $process = proc_open([PHP_BINARY, $packageBin . '/ichiloto', 'list', '--raw'], [1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);
    if (proc_close($process) !== 0 || ! str_contains($output, 'upgrade')) {
        $failures[] = 'the executable did not start from a package install: ' . $output;
    }
} finally {
    unlink($packageBin . '/ichiloto');
    unlink($home . '/vendor/autoload.php');
    rmdir($packageBin);
    rmdir(dirname($packageBin));
    rmdir(dirname($packageBin, 2));
    rmdir($home . '/vendor');
    rmdir($home);
}

if ($failures !== []) {
    fwrite(STDERR, 'FAIL: ' . implode("\nFAIL: ", $failures) . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: a Console that needs updating names the update for its checkout or Composer install, Windows paths included, and starts as an installed package.\n");
