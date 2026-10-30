<?php

declare(strict_types=1);

use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\Steps\MasterySpendStep;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Format 2 lists each Mastery Map's nodes in their old study order and
 * removes the order key in the map file's own source, keeps the costs, and
 * reports what needs a person. Synthetic fixtures only.
 */

$consoleRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-mastery-upgrade-' . bin2hex(random_bytes(8));

final class MasteryUpgradeFailure extends Exception
{
}

function failMasteryUpgrade(string $message): never
{
    throw new MasteryUpgradeFailure($message);
}

function removeMasteryUpgradeFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeMasteryUpgradeFixture($path) : unlink($path);
    }
    rmdir($directory);
}

/** A format 1 project with save metadata and, optionally, one Mastery Map source. */
function writeMasteryUpgradeProject(string $root, ?string $map): void
{
    mkdir($root . '/assets/Data/Mastery', 0777, true);
    file_put_contents($root . '/ichiloto.json', json_encode(['id' => 'studio/mastery-fixture', 'name' => 'Mastery Fixture', 'format' => 1],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($root . '/assets/Data/save-compatibility.php', "<?php\n\nreturn ['contentVersion' => 3];\n");
    file_put_contents($root . '/assets/Data/mastery.php', "<?php\n\nreturn ['regions' => [['id' => 'blades', 'name' => 'Blades']]];\n");
    if ($map !== null) {
        file_put_contents($root . '/assets/Data/Mastery/hero.php', $map);
    }
}

/** @return array{exitCode: int, output: string} */
function runMasteryUpgrade(string $consoleRoot, string $directory): array
{
    $pipes = [];
    $process = proc_open([PHP_BINARY, $consoleRoot . '/bin/ichiloto', 'upgrade', '--no-ansi', '--directory', $directory, '--yes'],
        [0 => ['pipe', 'r'], 1 => ['pipe', 'w'], 2 => ['pipe', 'w']], $pipes, $consoleRoot);
    if (! is_resource($process)) {
        failMasteryUpgrade('Unable to start the upgrade command.');
    }
    fclose($pipes[0]);
    $output = (string) stream_get_contents($pipes[1]) . (string) stream_get_contents($pipes[2]);
    fclose($pipes[1]);
    fclose($pipes[2]);

    return ['exitCode' => proc_close($process), 'output' => trim($output)];
}

$mapSource = <<<'PHP'
<?php

// The hero's map, kept by hand.
return [
  'actor' => 'Hero',
  'nodes' => [
    ['id' => 'storm', 'skill' => 'Storm', 'region' => 'blades', 'cost' => 300, 'opensAtLevel' => 5, 'order' => 3],
    ['id' => 'cut', 'skill' => 'Cut', 'region' => 'blades', 'cost' => 0, 'order' => 1],
    // Spark comes second.
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'blades', 'cost' => 120, 'opensAtLevel' => 2, 'order' => 2],
  ],
];

PHP;

try {
    $root = $temporaryRoot . '/ordered';
    writeMasteryUpgradeProject($root, $mapSource);
    $plan = new MasterySpendStep()->createPlan(new ProjectUpgradeContext($root));
    if ($plan->targetVersion !== 2 || $plan->writes === [] || count($plan->followUps) !== 2
        || file_get_contents($root . '/assets/Data/Mastery/hero.php') !== $mapSource) {
        failMasteryUpgrade('Planning format 2 wrote files, or did not plan the map rewrite and its follow-ups.');
    }

    $run = runMasteryUpgrade($consoleRoot, $root);
    $upgraded = (string) file_get_contents($root . '/assets/Data/Mastery/hero.php');
    // The whole chain runs, so the map is read as the current format reads it: the tree owns its skills.
    $map = MasteryCatalog::load($root . '/assets')->getMaps()['Hero'] ?? failMasteryUpgrade('The upgraded map could not be read.');
    $config = json_decode((string) file_get_contents($root . '/ichiloto.json'), true);
    $report = (string) file_get_contents($root . '/ichiloto-upgrade-report.md');

    if ($run['exitCode'] !== 0) {
        failMasteryUpgrade('The upgrade failed: ' . $run['output']);
    }
    if (array_keys($map->terms) !== ['cut', 'spark', 'storm'] || str_contains($upgraded, "'order'")
        || array_map(static fn($terms): int => $terms->cost, array_values($map->terms)) !== [0, 120, 300]) {
        failMasteryUpgrade("The map was not listed in study order without its order keys, or its costs changed:\n" . $upgraded);
    }
    if (! str_contains($upgraded, "// The hero's map, kept by hand.") || ! str_contains($upgraded, '// Spark comes second.')) {
        failMasteryUpgrade("The map's own source was not preserved:\n" . $upgraded);
    }
    if (($config['format'] ?? null) !== ProjectFormat::CURRENT || ! str_contains($report, 'Mastery points (SP)')
        || ! str_contains($report, 'content migration')) {
        failMasteryUpgrade('The project did not reach the current format, or the report does not ask for SP prices and a save migration.');
    }

    $again = new MasterySpendStep()->createPlan(new ProjectUpgradeContext($root));
    if ($again->writes !== []) {
        failMasteryUpgrade('Format 2 is not idempotent.');
    }

    $plain = $temporaryRoot . '/without-maps';
    writeMasteryUpgradeProject($plain, null);
    $none = new MasterySpendStep()->createPlan(new ProjectUpgradeContext($plain));
    if ($none->writes !== [] || $none->followUps !== []) {
        failMasteryUpgrade('A project without Mastery Maps was given changes or follow-ups.');
    }
} catch (MasteryUpgradeFailure $failure) {
    $testFailure = $failure->getMessage();
} finally {
    removeMasteryUpgradeFixture($temporaryRoot);
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: format 2 lists Mastery nodes in their study order without order keys, in their own source, and asks for SP prices.\n");
