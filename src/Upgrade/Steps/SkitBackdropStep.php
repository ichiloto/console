<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\Steps;

use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\ProjectUpgradeFollowUp;
use Ichiloto\Console\Upgrade\ProjectUpgradePlan;
use Ichiloto\Console\Upgrade\ProjectUpgradeReport;
use Ichiloto\Console\Upgrade\ProjectUpgradeStepInterface;
use Ichiloto\Editor\Cutscenes\Source\ArraySourceWriter;
use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Database\PhpDataFile;
use Ichiloto\Engine\Core\ProjectFormat;
use Ichiloto\Engine\Field\SkitBackdrop;
use Ichiloto\Engine\Messaging\Dialogue\Presentation\DialoguePresentationCatalog;
use InvalidArgumentException;
use RuntimeException;
use Throwable;

/**
 * Format 4: skit backdrops follow the place. The dialogue catalogue used to bind a background to each skit by id
 * under its `skits` key; the catalogue no longer has that key. Each background moves to its skit's own `backdrop`
 * (found by the skit's `id`, never its filename), which shows exactly what it showed before, and the key leaves the
 * catalogue, each file in its own source. A backdrop that belongs to a place, rather than to one skit's content, is
 * then the author's to move to the map (its Skit Backdrop) or the region. A binding for a skit the project does not
 * have is dropped and listed; a skit that already names another backdrop is refused rather than chosen for.
 */
final class SkitBackdropStep implements ProjectUpgradeStepInterface
{
    public const int VERSION = 4;
    private const string SKITS = 'assets/Data/Skits';
    private const string CATALOGUE = 'assets/' . DialoguePresentationCatalog::FILE;

    public function getTargetVersion(): int
    {
        return self::VERSION;
    }

    public function createPlan(ProjectUpgradeContext $context): ProjectUpgradePlan
    {
        $cataloguePath = $context->getPath(self::CATALOGUE);
        $catalogue = is_file($cataloguePath) ? self::evaluate($context, $cataloguePath) : [];
        if (! array_key_exists(DialoguePresentationCatalog::RETIRED_SKITS_KEY, $catalogue)) {
            return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION],
                ['The dialogue catalogue binds no skit backgrounds; nothing changes.']);
        }
        $bindings = $catalogue[DialoguePresentationCatalog::RETIRED_SKITS_KEY];
        if (! is_array($bindings)) {
            throw new RuntimeException(sprintf('%s "skits" must be a dictionary of skit ids.', self::CATALOGUE));
        }

        $skits = [];
        $paths = glob($context->getPath(self::SKITS . '/*.php')) ?: [];
        sort($paths, SORT_STRING);
        foreach ($paths as $path) {
            $skit = self::evaluate($context, $path);
            $id = trim(strval($skit['id'] ?? ''));
            if ($id !== '') { $skits[$id] = ['path' => $path, 'data' => $skit]; }
        }

        $changes = [];
        $writes = [];
        $conflicts = [];
        $dropped = [];
        foreach ($bindings as $id => $binding) {
            $id = (string) $id;
            $background = is_array($binding) ? ($binding['background'] ?? null) : null;
            if ($background === null) { continue; }
            try {
                $background = SkitBackdrop::validateAsset($background, sprintf('%s skit %s\'s background', self::CATALOGUE, $id));
            } catch (InvalidArgumentException $error) {
                $conflicts[] = $error->getMessage();
                continue;
            }
            if (! isset($skits[$id])) {
                $dropped[] = $id;
                continue;
            }
            ['path' => $path, 'data' => $skit] = $skits[$id];
            $authored = $skit[SkitBackdrop::SKIT_KEY] ?? null;
            if ($authored === $background) { continue; }
            if ($authored !== null) {
                $conflicts[] = sprintf('%s already names backdrop %s, and the dialogue catalogue binds it %s.',
                    $context->getRelativePath($path), is_scalar($authored) ? (string) $authored : get_debug_type($authored), $background);
                continue;
            }
            $writes[$path] = self::rewrite($path, $skit, self::withBackdrop($skit, $background));
            $changes[] = sprintf('Give skit %s its background %s as its own backdrop, in %s.', $id, $background, $context->getRelativePath($path));
        }
        if ($conflicts !== []) {
            throw new RuntimeException("Skit backgrounds cannot move to their skits:\n- " . implode("\n- ", $conflicts)
                . "\nResolve each in the files named, then run the upgrade again.");
        }

        $remaining = $catalogue;
        unset($remaining[DialoguePresentationCatalog::RETIRED_SKITS_KEY]);
        $document = ArraySourceWriter::rewrite(PhpArraySourceDocument::parse((string) file_get_contents($cataloguePath)), $catalogue, $remaining);
        $writes[$cataloguePath] = $document->source;
        $changes[] = sprintf('Remove the skits key from %s.', self::CATALOGUE);
        foreach ($dropped as $id) {
            $changes[] = sprintf('Drop the background bound to skit %s, which the project does not have.', $id);
        }

        $followUps = [new ProjectUpgradeFollowUp('Skit backdrops', 'A skit now shows its own backdrop only when its content needs one '
            . 'setting; otherwise it shows the backdrop of the place it plays in. Where a moved backdrop belongs to a place, choose it '
            . 'on that map\'s Skit Backdrop row (or its region on the Regions page) and set the skit\'s Backdrop to None.')];
        // A variable only the skits read is left to the author, as all of a file's own code is.
        $unused = array_filter(array_map(strval(...), array_keys($document->variables())),
            static fn(string $name): bool => $document->referenceCount($name) === 0);
        if ($unused !== []) {
            $followUps[] = new ProjectUpgradeFollowUp('Skit backdrops', sprintf('%s still assigns %s, which nothing reads now.',
                self::CATALOGUE, implode(', ', array_map(static fn(string $name): string => '$' . $name, $unused))));
        }

        return new ProjectUpgradePlan(self::VERSION, ProjectFormat::CHANGES[self::VERSION], $changes, $writes, $followUps);
    }

    public function applyPlan(ProjectUpgradeContext $context, ProjectUpgradePlan $plan): ProjectUpgradeReport
    {
        return ProjectUpgradeReport::applyPlan($context, $plan);
    }

    /**
     * The skit with its backdrop beside its map gate (or its title), where an author reads its setting.
     *
     * @param array<array-key, mixed> $skit
     * @return array<array-key, mixed>
     */
    private static function withBackdrop(array $skit, string $backdrop): array
    {
        $anchor = array_key_exists('where', $skit) ? 'where' : (array_key_exists('title', $skit) ? 'title' : 'id');
        $result = [];
        foreach ($skit as $key => $value) {
            $result[$key] = $value;
            if ($key === $anchor) { $result[SkitBackdrop::SKIT_KEY] = $backdrop; }
        }
        $result[SkitBackdrop::SKIT_KEY] ??= $backdrop;

        return $result;
    }

    /** @return array<array-key, mixed> */
    private static function evaluate(ProjectUpgradeContext $context, string $path): array
    {
        try {
            $data = PhpDataFile::evaluateIsolated($path, $context->root);
        } catch (Throwable $error) {
            throw new RuntimeException(sprintf('%s could not be read: %s', $context->getRelativePath($path), $error->getMessage()), previous: $error);
        }

        return is_array($data) ? $data : [];
    }

    /**
     * @param array<array-key, mixed> $old
     * @param array<array-key, mixed> $new
     */
    private static function rewrite(string $path, array $old, array $new): string
    {
        return ArraySourceWriter::rewrite(PhpArraySourceDocument::parse((string) file_get_contents($path)), $old, $new)->source;
    }
}
