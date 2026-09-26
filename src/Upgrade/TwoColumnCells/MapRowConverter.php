<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Engine\Field\MapCell;
use Ichiloto\Engine\IO\Console\NormalizedRow;
use Ichiloto\Engine\IO\Console\TerminalText;

/**
 * Groups one authored row of one-column cells into two-column cells without
 * changing its terminal art. The row is edited as written, markup and all:
 * a row of odd width gains one trailing space, and a two-column glyph that
 * would start halfway through a cell gets a space inserted before it.
 */
final class MapRowConverter
{
    /**
     * Raw row tokens: terminal escape sequences and Symfony formatter tags,
     * which have no width, and graphemes. Mirrors what TerminalText reads.
     */
    private const string TOKEN_PATTERN = '/\x1B\[[0-9;?]*[ -\/]*[@-~]|<\/?[-\w=;#,?]+>|\X/u';

    /**
     * @return array{text: string, width: int, padded: bool, insertions: list<array{column: int, glyph: string}>, error: ?string}
     *   The converted row; its new display width; whether it gained a
     *   trailing space; each glyph moved right, by its original column; and
     *   why the row could not be aligned, when it could not.
     */
    public function convertRow(string $row): array
    {
        $symbols = TerminalText::visibleSymbols($row);
        $widths = array_map(NormalizedRow::symbolWidth(...), $symbols);
        $insertAt = [];
        $column = 0;

        foreach ($widths as $index => $width) {
            if ($width >= MapCell::COLUMNS && $column % MapCell::COLUMNS !== 0) {
                $insertAt[$index] = $column;
                $column++;
            }
            $column += $width;
        }

        $text = $row;
        $insertions = [];

        if ($insertAt !== []) {
            $offsets = $this->findSymbolOffsets($row, $symbols);

            if ($offsets === null) {
                return [
                    'text' => $row,
                    'width' => array_sum($widths),
                    'padded' => false,
                    'insertions' => [],
                    'error' => 'its markup could not be matched to its glyphs, so the space before a two-column glyph could not be placed',
                ];
            }

            foreach (array_reverse($insertAt, true) as $index => $newColumn) {
                $text = substr($text, 0, $offsets[$index]) . ' ' . substr($text, $offsets[$index]);
            }

            $shift = 0;
            foreach ($insertAt as $index => $newColumn) {
                $insertions[] = ['column' => $newColumn - $shift, 'glyph' => TerminalText::stripAnsi($symbols[$index])];
                $shift++;
            }
        }

        $padded = $column % MapCell::COLUMNS !== 0;

        if ($padded) {
            $text .= ' ';
            $column++;
        }

        return ['text' => $text, 'width' => $column, 'padded' => $padded, 'insertions' => $insertions, 'error' => null];
    }

    /**
     * The byte offset in the raw row at which each visible symbol's grapheme
     * begins, or null when the raw tokens do not match the symbols the engine
     * reads (an escaped or unknown tag, for example).
     *
     * @param list<string> $symbols
     * @return list<int>|null
     */
    private function findSymbolOffsets(string $row, array $symbols): ?array
    {
        if (preg_match_all(self::TOKEN_PATTERN, $row, $matches, PREG_OFFSET_CAPTURE) === false) {
            return null;
        }

        $offsets = [];
        $graphemes = [];

        foreach ($matches[0] as [$token, $offset]) {
            if ($token === '' || str_starts_with($token, "\e") || preg_match('/\A<\/?[-\w=;#,?]+>\z/', $token) === 1) {
                continue;
            }
            $offsets[] = $offset;
            $graphemes[] = $token;
        }

        return $graphemes === array_map(TerminalText::stripAnsi(...), $symbols) ? $offsets : null;
    }
}
