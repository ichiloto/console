<?php

declare(strict_types=1);

use Ichiloto\Console\Upgrade\ProjectUpgradeChain;
use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\Steps\MasteryTreeStep;
use Ichiloto\Engine\Progression\Mastery\MasteryCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Format 3 moves each node's skill and region from the characters' maps
 * onto the shared mastery tree's region trunks under the same ids, in each
 * file's own source, so saves keep their learned ids. It refuses maps that
 * would need a saved id renamed. Synthetic fixtures only.
 */

$consoleRoot = dirname(__DIR__);
$temporaryRoot = sys_get_temp_dir() . '/ichiloto-mastery-tree-upgrade-' . bin2hex(random_bytes(8));

final class MasteryTreeUpgradeFailure extends Exception
{
}

function failMasteryTreeUpgrade(string $message): never
{
    throw new MasteryTreeUpgradeFailure($message);
}

function removeMasteryTreeFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeMasteryTreeFixture($path) : unlink($path);
    }
    rmdir($directory);
}

/** A format 2 project with two regions and the given map sources. */
function writeMasteryTreeProject(string $root, array $maps): void
{
    mkdir($root . '/assets/Data/Mastery', 0777, true);
    mkdir($root . '/assets/Data/Skills', 0777, true);
    file_put_contents($root . '/ichiloto.json', json_encode(['id' => 'studio/mastery-tree-fixture', 'name' => 'Tree Fixture', 'format' => 2],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($root . '/assets/Data/save-compatibility.php', "<?php\n\nreturn ['contentVersion' => 3];\n");
    file_put_contents($root . '/assets/Data/mastery.php', <<<'PHP'
<?php

return [
  'regions' => [
    // Weapon techniques.
    ['id' => 'blades', 'name' => 'Blades'],
    ['id' => 'storms', 'name' => 'Storms'],
  ],
];

PHP);
    foreach ($maps as $file => $source) {
        file_put_contents($root . '/assets/Data/Mastery/' . $file, $source);
    }
}

$heroMap = <<<'PHP'
<?php

// The hero's map, kept by hand.
return [
  'actor' => 'Hero',
  'nodes' => [
    ['id' => 'cut', 'skill' => 'Cut', 'region' => 'blades', 'cost' => 0],
    // Spark builds on nothing here.
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 120, 'opensAtLevel' => 2],
  ],
];

PHP;
$allyMap = <<<'PHP'
<?php

return [
  'actor' => 'Ally',
  'nodes' => [
    ['id' => 'spark', 'skill' => 'Spark', 'region' => 'storms', 'cost' => 60],
    ['id' => 'gale', 'skill' => 'Gale', 'region' => 'storms', 'cost' => 90, 'requires' => ['spark']],
  ],
];

PHP;

try {
    $root = $temporaryRoot . '/maps';
    writeMasteryTreeProject($root, ['ally.php' => $allyMap, 'hero.php' => $heroMap]);
    $plan = new MasteryTreeStep()->createPlan(new ProjectUpgradeContext($root));
    if ($plan->targetVersion !== 3 || count($plan->writes) !== 3 || count($plan->followUps) !== 1
        || file_get_contents($root . '/assets/Data/Mastery/hero.php') !== $heroMap) {
        failMasteryTreeUpgrade('Planning format 3 wrote files, or did not plan the tree, both maps and the follow-up.');
    }
    foreach ($plan->writes as $path => $source) {
        file_put_contents($path, $source);
    }

    $settings = require $root . '/assets/Data/mastery.php';
    $trunks = array_map(static fn(array $region): array => array_column($region['nodes'] ?? [], 'skill', 'id'), $settings['regions']);
    if ($trunks !== [['cut' => 'Cut'], ['spark' => 'Spark', 'gale' => 'Gale']]) {
        failMasteryTreeUpgrade('The trunks do not hold each taught skill once, under its id: ' . json_encode($trunks));
    }
    $hero = (string) file_get_contents($root . '/assets/Data/Mastery/hero.php');
    if (str_contains($hero, "'skill'") || str_contains($hero, "'region'") || ! str_contains($hero, "// The hero's map, kept by hand.")
        || ! str_contains($hero, '// Spark builds on nothing here.') || ! str_contains((string) file_get_contents($root . '/assets/Data/mastery.php'), '// Weapon techniques.')) {
        failMasteryTreeUpgrade("The maps kept a skill or region, or a file lost its own source:\n" . $hero);
    }

    $catalog = MasteryCatalog::load($root . '/assets');
    $ally = $catalog->findMap('Ally');
    if ($catalog->problems !== [] || $ally === null || $ally->findNode('spark')?->cost !== 60 || $ally->findNode('gale')?->requires !== ['spark']
        || $catalog->findMap('Hero')?->findNode('spark')?->cost !== 120 || $catalog->findMap('Stranger')?->findNode('cut')?->cost !== null) {
        failMasteryTreeUpgrade('The upgraded project does not read each character\'s own terms over the shared tree: ' . implode('; ', $catalog->problems));
    }
    if (new MasteryTreeStep()->createPlan(new ProjectUpgradeContext($root))->writes !== []) {
        failMasteryTreeUpgrade('Format 3 is not idempotent.');
    }

    $clash = $temporaryRoot . '/clash';
    writeMasteryTreeProject($clash, ['hero.php' => $heroMap, 'ally.php' => str_replace("'id' => 'spark', 'skill' => 'Spark'", "'id' => 'ally-spark', 'skill' => 'Spark'", $allyMap)]);
    try {
        new MasteryTreeStep()->createPlan(new ProjectUpgradeContext($clash));
        failMasteryTreeUpgrade('Two ids teaching one skill were merged without a saved-id migration.');
    } catch (RuntimeException $refused) {
        if (! str_contains($refused->getMessage(), 'which node ally-spark already teaches') || ! str_contains($refused->getMessage(), 'content migration')) {
            failMasteryTreeUpgrade('The refusal does not name the clash and the migration it needs: ' . $refused->getMessage());
        }
    }

    $plain = $temporaryRoot . '/without-maps';
    writeMasteryTreeProject($plain, []);
    if (new MasteryTreeStep()->createPlan(new ProjectUpgradeContext($plain))->writes !== [] || ProjectUpgradeChain::createDefault()->getPendingSteps(2, MasteryTreeStep::VERSION)[0]->getTargetVersion() !== MasteryTreeStep::VERSION) {
        failMasteryTreeUpgrade('A project without Mastery Maps was given changes, or format 3 is not the step after format 2.');
    }
} catch (MasteryTreeUpgradeFailure $failure) {
    $testFailure = $failure->getMessage();
} finally {
    removeMasteryTreeFixture($temporaryRoot);
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: format 3 moves each map node's skill and region onto the shared tree under its id, in each file's own source, and refuses saved-id clashes.\n");
