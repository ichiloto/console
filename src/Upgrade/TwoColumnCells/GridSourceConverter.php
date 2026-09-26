<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use Ichiloto\Engine\Field\MapGridSource;
use Ichiloto\Engine\Field\MapLayer;
use InvalidArgumentException;
use PhpToken;

/**
 * Converts one authored grid file (a map layer or an event layer) to
 * two-column cells. The file's PHP is never evaluated: MapGridSource reads
 * the nowdoc, only the bytes of its body change, and everything outside the
 * body is kept byte for byte. The result must read back through the engine.
 */
final readonly class GridSourceConverter
{
    public function __construct(private MapRowConverter $rows = new MapRowConverter())
    {
    }

    /**
     * @param string $source The grid file's bytes.
     * @param string $displayPath The project-relative path, for messages.
     * @return array{
     *   source: string,
     *   originalText: string,
     *   text: string,
     *   originalWidths: list<int>,
     *   paddedRows: int,
     *   insertions: list<array{row: int, column: int, glyph: string}>,
     *   errors: list<string>
     * }
     * @throws InvalidArgumentException When the file is not a literal grid.
     */
    public function convertSource(string $source, string $displayPath): array
    {
        $originalText = MapGridSource::parseSource($source, $displayPath);
        $content = $this->findBodySpan($source);
        $indent = $content['indent'];
        $raw = substr($source, $content['start'], $content['end'] - $content['start']);
        $parts = preg_split('/(\r\n|\n|\r)/', $raw, -1, PREG_SPLIT_DELIM_CAPTURE) ?: [$raw];
        // PHP drops the line break before the closing marker, so the part
        // after it is the (empty) remainder, not a row.
        $rowCount = intdiv(count($parts) - 1, 2);
        $paddedRows = 0;
        $insertions = [];
        $errors = [];
        $originalWidths = [];

        for ($row = 0; $row < $rowCount; $row++) {
            $line = $parts[$row * 2];
            $prefix = '';

            if ($indent !== '') {
                if (str_starts_with($line, $indent)) {
                    $prefix = $indent;
                    $line = substr($line, strlen($indent));
                } elseif (trim($line, " \t") === '') {
                    $prefix = $line;
                    $line = '';
                }
            }

            $converted = $this->rows->convertRow($line);
            $originalWidths[] = $converted['width'] - ($converted['padded'] ? 1 : 0) - count($converted['insertions']);

            if ($converted['error'] !== null) {
                $errors[] = "row {$row}: {$converted['error']}";
            }

            if ($converted['padded']) {
                $paddedRows++;
            }

            foreach ($converted['insertions'] as $insertion) {
                $insertions[] = ['row' => $row] + $insertion;
            }

            $parts[$row * 2] = $prefix . $converted['text'];
        }

        $updated = substr($source, 0, $content['start']) . implode('', $parts) . substr($source, $content['end']);
        $text = MapGridSource::parseSource($updated, $displayPath);

        if ($errors === []) {
            // The engine must read every converted row as whole cells.
            MapLayer::parseGrid($text, $displayPath);
        }

        return [
            'source' => $updated,
            'originalText' => $originalText,
            'text' => $text,
            'originalWidths' => $originalWidths,
            'paddedRows' => $paddedRows,
            'insertions' => $insertions,
            'errors' => $errors,
        ];
    }

    /**
     * The byte span of the nowdoc body, including the line break before the
     * closing marker, and the closing marker's indentation.
     *
     * @return array{start: int, end: int, indent: string}
     */
    private function findBodySpan(string $source): array
    {
        $tokens = PhpToken::tokenize($source);

        foreach ($tokens as $index => $token) {
            if (! $token->is(T_START_HEREDOC)) {
                continue;
            }

            $start = $token->pos + strlen($token->text);
            $next = $tokens[$index + 1] ?? null;
            $endToken = $next !== null && $next->is(T_ENCAPSED_AND_WHITESPACE) ? ($tokens[$index + 2] ?? null) : $next;

            if ($endToken === null || ! $endToken->is(T_END_HEREDOC)) {
                break;
            }

            preg_match('/\A[ \t]*/', $endToken->text, $matches);

            return ['start' => $start, 'end' => $endToken->pos, 'indent' => $matches[0] ?? ''];
        }

        throw new InvalidArgumentException('The grid source has no nowdoc body.');
    }
}
