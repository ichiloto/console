<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Editor\Cutscenes\Source\PhpArraySourceDocument;
use Ichiloto\Editor\Cutscenes\Source\SourceNode;
use PhpToken;
use RuntimeException;

/**
 * Adds one content migration to the end of a project's save compatibility
 * chain, in the manifest's own source: the migration entry is appended to
 * `migrations`, `contentVersion` moves up by one, and the class is imported
 * beside the manifest's other `use` statements. Nothing else changes, and
 * the manifest is never evaluated (it names the project's own classes).
 */
final class SaveMigrationAppender
{
    /**
     * @param string $source The manifest's bytes.
     * @param class-string $class The migration class.
     * @return array{source: string, from: int, to: int, added: bool} The
     *   rewritten manifest and the step it registers; `added` is false when
     *   the class is already registered.
     */
    public function appendMigration(string $source, string $class): array
    {
        $document = PhpArraySourceDocument::parse($source);
        $root = $document->root();
        $versionEntry = $root->entryFor('contentVersion');
        $version = $versionEntry !== null && $versionEntry->value->kind === SourceNode::SCALAR
            ? substr($source, $versionEntry->value->start, $versionEntry->value->end - $versionEntry->value->start)
            : null;

        if ($version === null || preg_match('/\A[0-9]+\z/', $version) !== 1) {
            throw new RuntimeException('contentVersion is not an integer literal, so the save migration cannot be appended automatically.');
        }

        $from = (int) $version;
        $shortName = substr($class, (int) strrpos($class, '\\') + 1);

        if (str_contains($source, $shortName . '::class') || str_contains($source, $class . '::class')) {
            return ['source' => $source, 'from' => $from - 1, 'to' => $from, 'added' => false];
        }

        $imported = $this->isImported($source, $class);
        $reference = $imported || ! $this->usesName($source, $shortName) ? $shortName : '\\' . $class;
        $migrations = $root->entryFor('migrations');
        $container = $migrations === null ? $root : $migrations->value;
        // A manifest written on one line gets its new entry on that line too.
        $inline = $container->entries !== [] && $document->lineIndentBefore($container->entries[0]->start) === null;
        $entry = $inline
            ? "['from' => {$from}, 'to' => " . ($from + 1) . ", 'class' => {$reference}::class]"
            : "[\n  'from' => {$from},\n  'to' => " . ($from + 1) . ",\n  'class' => {$reference}::class,\n]";
        $firstMigration = $inline ? "[{$entry}]" : "[\n  " . str_replace("\n", "\n  ", $entry) . ",\n]";
        $edits = [[$versionEntry->value->start, $versionEntry->value->end, (string) ($from + 1)]];

        if ($migrations === null) {
            $edits[] = $document->insertEntryEdit([], count($root->entries), 'migrations', $firstMigration);
        } elseif ($migrations->value->kind === SourceNode::ARRAY && $migrations->value->entries === []) {
            // An empty list becomes the first migration, indented from its key's line.
            $edits[] = $document->replaceValueEdit(['migrations'], $firstMigration);
        } elseif ($migrations->value->kind === SourceNode::ARRAY) {
            $edits[] = $document->insertEntryEdit(['migrations'], count($migrations->value->entries), null, $entry);
        } else {
            throw new RuntimeException('migrations is not an array literal, so the save migration cannot be appended automatically.');
        }

        $updated = $document->withEdits($edits)->source;

        if (! $imported && $reference === $shortName) {
            $updated = $this->addImport($updated, $class);
        }

        // The result must read back with the new step at the end of the chain.
        $check = PhpArraySourceDocument::parse($updated);
        $newVersion = $check->root()->entryFor('contentVersion');
        if ($newVersion === null || substr($updated, $newVersion->value->start, $newVersion->value->end - $newVersion->value->start) !== (string) ($from + 1)) {
            throw new RuntimeException('The save migration could not be appended without changing other manifest data.');
        }

        return ['source' => $updated, 'from' => $from, 'to' => $from + 1, 'added' => true];
    }

    private function isImported(string $source, string $class): bool
    {
        return preg_match('/^use\s+\\\\?' . preg_quote($class, '/') . '\s*;/m', $source) === 1;
    }

    /** Whether the short name is already taken by another import or class. */
    private function usesName(string $source, string $shortName): bool
    {
        return preg_match('/\b' . preg_quote($shortName, '/') . '\b/', $source) === 1;
    }

    /** Imports a class after the last top-level `use`, or before the `return`. */
    private function addImport(string $source, string $class): string
    {
        $line = "use {$class};\n";
        $lastUseEnd = null;
        $returnStart = null;
        $depth = 0;
        $tokens = PhpToken::tokenize($source);
        $previous = null;

        foreach ($tokens as $index => $token) {
            if ($token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT])) {
                continue;
            }

            if (in_array($token->text, ['(', '[', '{'], true)) {
                $depth++;
            } elseif (in_array($token->text, [')', ']', '}'], true)) {
                $depth--;
            }

            $startsStatement = $previous === null || $previous->is(T_OPEN_TAG) || $previous->text === ';';
            $previous = $token;

            if ($depth === 0 && $startsStatement && $token->is(T_USE)) {
                for ($end = $index; $end < count($tokens) && $tokens[$end]->text !== ';'; $end++) {
                }
                $lastUseEnd = $tokens[$end]->pos + 1;
            }

            if ($depth === 0 && $token->is(T_RETURN)) {
                $returnStart = $token->pos;
                break;
            }
        }

        if ($lastUseEnd !== null) {
            $lineEnd = strpos($source, "\n", $lastUseEnd);
            $at = $lineEnd === false ? strlen($source) : $lineEnd + 1;

            return substr($source, 0, $at) . $line . substr($source, $at);
        }

        if ($returnStart === null) {
            throw new RuntimeException('The manifest has no top-level return.');
        }

        $at = (int) strrpos(substr($source, 0, $returnStart), "\n") + 1;

        return substr($source, 0, $at) . $line . "\n" . substr($source, $at);
    }
}
