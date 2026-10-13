<?php

declare(strict_types=1);

use Ichiloto\Console\Commands\ValidateCommand;
use Ichiloto\Console\Support\NewProjectScaffolder;
use Laravel\Prompts\ConfirmPrompt;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Tester\CommandTester;

require dirname(__DIR__) . '/vendor/autoload.php';

$projectRoot = sys_get_temp_dir() . '/ichiloto-interactive-validation-' . bin2hex(random_bytes(8));

/** @return array{code: int, output: string, prompts: int} */
function runInteractiveValidation(string $projectRoot, bool $answer): array
{
    $prompts = 0;
    ConfirmPrompt::fallbackUsing(static function (ConfirmPrompt $prompt) use ($answer, &$prompts): bool {
        $prompts++;
        return $answer;
    });
    ConfirmPrompt::fallbackWhen(true);
    $tester = new CommandTester(new ValidateCommand());
    $code = $tester->execute(['--directory' => $projectRoot], ['interactive' => true]);
    return ['code' => $code, 'output' => $tester->getDisplay(), 'prompts' => $prompts];
}

function removeInteractiveProject(string $root): void
{
    if (! is_dir($root)) { return; }
    $entries = new RecursiveIteratorIterator(
        new RecursiveDirectoryIterator($root, FilesystemIterator::SKIP_DOTS),
        RecursiveIteratorIterator::CHILD_FIRST,
    );
    foreach ($entries as $entry) {
        $entry->isDir() && ! $entry->isLink() ? rmdir($entry->getPathname()) : unlink($entry->getPathname());
    }
    rmdir($root);
}

try {
    new NewProjectScaffolder()->scaffold([
        'displayName' => 'Interactive Validation',
        'directoryName' => 'interactive-validation',
        'targetDirectory' => $projectRoot,
        'heroName' => 'Aria Vale',
        'heroId' => 'AriaVale',
        'battleEngine' => 'traditional',
        'titleArt' => "INTERACTIVE VALIDATION\n",
    ]);
    $actorPath = $projectRoot . '/assets/Data/Actors/AriaVale.php';
    $systemPath = $projectRoot . '/assets/Data/system.php';

    $clean = runInteractiveValidation($projectRoot, false);
    if ($clean['code'] !== Command::SUCCESS || $clean['prompts'] !== 0
        || str_contains($clean['output'], 'Actor identity migration would update')) {
        throw new RuntimeException('Clean interactive validation unnecessarily offered actor migration: ' . $clean['output']);
    }

    $presentationDirectory = $projectRoot . '/assets/Data/Presentation';
    mkdir($presentationDirectory, 0700, true);
    $presentationPath = $presentationDirectory . '/menus.php';
    file_put_contents($presentationPath, "<?php throw new RuntimeException('unrelated presentation probe');\n");
    $unrelated = runInteractiveValidation($projectRoot, false);
    if ($unrelated['code'] !== Command::FAILURE || $unrelated['prompts'] !== 0
        || ! str_contains($unrelated['output'], 'unrelated presentation probe')
        || str_contains($unrelated['output'], 'Actor id migration could not be planned')) {
        throw new RuntimeException('An unrelated validation error invoked actor migration planning: ' . $unrelated['output']);
    }
    unlink($presentationPath);
    rmdir($presentationDirectory);

    $actorSource = (string) file_get_contents($actorPath);
    $legacyActorSource = str_replace("    'id' => 'Aria Vale',\n", '', $actorSource, $removedIds);
    if ($removedIds !== 1) {
        throw new RuntimeException('Could not create an actor missing its stable id.');
    }
    file_put_contents($actorPath, $legacyActorSource);
    $declined = runInteractiveValidation($projectRoot, false);
    if ($declined['code'] !== Command::FAILURE || $declined['prompts'] !== 1
        || file_get_contents($actorPath) !== $legacyActorSource
        || ! str_contains($declined['output'], 'AriaVale.php')) {
        throw new RuntimeException('Declining actor migration changed source or skipped confirmation: ' . $declined['output']);
    }
    $accepted = runInteractiveValidation($projectRoot, true);
    $actor = require $actorPath;
    if ($accepted['code'] !== Command::SUCCESS || $accepted['prompts'] !== 1
        || ($actor['data']['id'] ?? null) !== 'Aria Vale') {
        throw new RuntimeException('Confirmed interactive actor migration failed: ' . $accepted['output']);
    }

    $systemSource = (string) file_get_contents($systemPath);
    $staleSystemSource = str_replace("'Aria Vale',", "'AriaVale',", $systemSource, $replacedReferences);
    if ($replacedReferences !== 1) {
        throw new RuntimeException('Could not create a stale starting-party reference.');
    }
    file_put_contents($systemPath, $staleSystemSource);
    $referenceRepair = runInteractiveValidation($projectRoot, true);
    $system = require $systemPath;
    if ($referenceRepair['code'] !== Command::SUCCESS || $referenceRepair['prompts'] !== 1
        || ($system['startingParty'] ?? null) !== ['Aria Vale']
        || ! str_contains($referenceRepair['output'], 'system.php')) {
        throw new RuntimeException('Confirmed interactive stale-reference repair failed: ' . $referenceRepair['output']);
    }
} catch (Throwable $failure) {
    $failureMessage = $failure->getMessage();
} finally {
    removeInteractiveProject($projectRoot);
}

if (isset($failureMessage)) {
    fwrite(STDERR, 'FAIL: ' . $failureMessage . "\n");
    exit(1);
}

fwrite(STDOUT, "PASS: interactive validation plans only actor migration candidates and confirms every source change.\n");
