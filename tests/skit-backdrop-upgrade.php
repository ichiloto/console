<?php

declare(strict_types=1);

use Ichiloto\Console\Upgrade\ProjectUpgradeChain;
use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\Steps\SkitBackdropStep;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Field\Enumerations\SkitBackdropSource;
use Ichiloto\Engine\Field\RegionCatalog;
use Ichiloto\Engine\Field\SkitBackdrop;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;

require dirname(__DIR__) . '/vendor/autoload.php';

/**
 * Format 4 moves each background the dialogue catalogue bound to a skit onto that skit's own backdrop, found by the
 * skit's id, so every skit shows what it showed before, and removes the catalogue's skits key, each file in its own
 * source. It refuses a skit that already names another backdrop. Synthetic fixtures only.
 */

$temporaryRoot = sys_get_temp_dir() . '/ichiloto-skit-backdrop-upgrade-' . bin2hex(random_bytes(8));

final class SkitBackdropUpgradeFailure extends Exception
{
}

function failSkitBackdropUpgrade(string $message): never
{
    throw new SkitBackdropUpgradeFailure($message);
}

function removeSkitBackdropFixture(string $directory): void
{
    if (! is_dir($directory)) {
        return;
    }
    foreach (scandir($directory) ?: [] as $entry) {
        if ($entry === '.' || $entry === '..') {
            continue;
        }
        $path = $directory . DIRECTORY_SEPARATOR . $entry;
        is_dir($path) ? removeSkitBackdropFixture($path) : unlink($path);
    }
    rmdir($directory);
}

/** A format 3 project whose dialogue catalogue binds skit backgrounds. */
function writeSkitBackdropProject(string $root, string $catalogue, array $skits): void
{
    mkdir($root . '/assets/Data/Presentation', 0777, true);
    mkdir($root . '/assets/Data/Skits', 0777, true);
    file_put_contents($root . '/ichiloto.json', json_encode(['id' => 'studio/skit-backdrop-fixture', 'name' => 'Backdrop Fixture', 'format' => 3],
        JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES) . PHP_EOL);
    file_put_contents($root . '/assets/Data/Presentation/dialogue.php', $catalogue);
    foreach ($skits as $file => $source) {
        file_put_contents($root . '/assets/Data/Skits/' . $file, $source);
    }
}

$catalogue = <<<'PHP'
<?php

// Kept by hand.
$squareBackground = 'Graphics/Skits/Square/Day.png';

return [
  'schema' => 'ichiloto.dialogue/1',
  'speakers' => [],
  // Two conversations share the square.
  'skits' => [
    'square-talk' => ['background' => $squareBackground],
    'square-again' => ['background' => $squareBackground],
    'gone' => ['background' => 'Graphics/Skits/Gone.png'],
  ],
  'skitStage' => ['inactiveScale' => 0.9],
];

PHP;
$squareTalk = <<<'PHP'
<?php

// The first square conversation.
return [
  'id' => 'square-talk',
  'title' => 'Square Talk',
  'where' => 'town/square',
  'beats' => [['speaker' => 'Guide', 'text' => 'Hello.']],
];

PHP;
$squareAgain = <<<'PHP'
<?php

return [
  'id' => 'square-again',
  'title' => 'Again',
  'beats' => [['speaker' => 'Guide', 'text' => 'Hello again.']],
];

PHP;
$unbound = <<<'PHP'
<?php

return ['id' => 'road-talk', 'title' => 'Road Talk', 'beats' => [['speaker' => 'Guide', 'text' => 'Onward.']]];

PHP;

try {
    $root = $temporaryRoot . '/bound';
    writeSkitBackdropProject($root, $catalogue, ['first.php' => $squareTalk, 'second.php' => $squareAgain, 'road.php' => $unbound]);
    $context = new ProjectUpgradeContext($root);
    $plan = new SkitBackdropStep()->createPlan($context);
    if ($plan->targetVersion !== 4 || count($plan->writes) !== 3
        || file_get_contents($root . '/assets/Data/Skits/first.php') !== $squareTalk
        || ! in_array('Drop the background bound to skit gone, which the project does not have.', $plan->changes, true)
        || ! array_any($plan->followUps, static fn($item): bool => str_contains($item->message, '$squareBackground, which nothing reads now'))) {
        failSkitBackdropUpgrade("Planning format 4 wrote files, or did not plan both skits, the catalogue, the dropped binding and the follow-ups:\n"
            . implode("\n", $plan->changes));
    }
    new SkitBackdropStep()->applyPlan($context, $plan);

    $first = (string) file_get_contents($root . '/assets/Data/Skits/first.php');
    if (! str_contains($first, "// The first square conversation.")
        || ! str_contains($first, "  'where' => 'town/square',\n  'backdrop' => 'Graphics/Skits/Square/Day.png',\n")) {
        failSkitBackdropUpgrade("The skit lost its own source, or its backdrop is not beside its map gate:\n" . $first);
    }
    if (file_get_contents($root . '/assets/Data/Skits/road.php') !== $unbound) {
        failSkitBackdropUpgrade('A skit the catalogue bound nothing to was rewritten.');
    }
    $written = (string) file_get_contents($root . '/assets/Data/Presentation/dialogue.php');
    if (str_contains($written, "'skits'") || ! str_contains($written, '// Kept by hand.') || ! str_contains($written, "'skitStage' => ['inactiveScale' => 0.9]")) {
        failSkitBackdropUpgrade("The catalogue kept its skits key or lost its own source:\n" . $written);
    }

    // Each skit shows on any map what it showed before; the catalogue now loads.
    $loaded = DialoguePresentationCatalog::load($root . '/assets');
    foreach (['first.php', 'second.php'] as $file) {
        $backdrop = SkitBackdrop::resolve(require $root . '/assets/Data/Skits/' . $file, ['region' => 'Elsewhere'], new RegionCatalog());
        if ($backdrop->asset !== 'Graphics/Skits/Square/Day.png' || $backdrop->source !== SkitBackdropSource::SKIT) {
            failSkitBackdropUpgrade(sprintf('%s no longer shows its background: %s', $file, $backdrop->asset ?? 'none'));
        }
    }
    if ($loaded->skitStage->inactiveScale !== 0.9) {
        failSkitBackdropUpgrade('The catalogue\'s other settings changed.');
    }
    if (new SkitBackdropStep()->createPlan($context)->writes !== []) {
        failSkitBackdropUpgrade('Format 4 is not idempotent.');
    }

    // A skit that already names another backdrop is the author's to resolve.
    $clash = $temporaryRoot . '/clash';
    writeSkitBackdropProject($clash, $catalogue, ['first.php' => str_replace("'where' => 'town/square',",
        "'where' => 'town/square',\n  'backdrop' => 'Graphics/Skits/Other.png',", $squareTalk), 'second.php' => $squareAgain]);
    try {
        new SkitBackdropStep()->createPlan(new ProjectUpgradeContext($clash));
        failSkitBackdropUpgrade('A skit\'s own backdrop was replaced by the catalogue\'s background.');
    } catch (RuntimeException $refused) {
        if (! str_contains($refused->getMessage(), 'first.php already names backdrop Graphics/Skits/Other.png')) {
            failSkitBackdropUpgrade('The refusal does not name the skit and both backdrops: ' . $refused->getMessage());
        }
    }

    // A catalogue without the key, or no catalogue, needs nothing.
    $plain = $temporaryRoot . '/plain';
    writeSkitBackdropProject($plain, "<?php\n\nreturn ['schema' => 'ichiloto.dialogue/1'];\n", ['road.php' => $unbound]);
    unlink($plain . '/assets/Data/Presentation/dialogue.php');
    if (new SkitBackdropStep()->createPlan(new ProjectUpgradeContext($plain))->writes !== []
        || ProjectFormat::CURRENT !== SkitBackdropStep::VERSION
        || ProjectUpgradeChain::createDefault()->getPendingSteps(3)[0]->getTargetVersion() !== SkitBackdropStep::VERSION) {
        failSkitBackdropUpgrade('A project without skit backgrounds was given changes, or format 4 is not the current step.');
    }
} catch (SkitBackdropUpgradeFailure $failure) {
    $testFailure = $failure->getMessage();
} finally {
    removeSkitBackdropFixture($temporaryRoot);
}

if (isset($testFailure)) {
    fwrite(STDERR, "FAIL: {$testFailure}\n");
    exit(1);
}

fwrite(STDOUT, "PASS: format 4 moves each skit background from the dialogue catalogue to its skit, in each file's own source, and refuses a skit naming another backdrop.\n");
