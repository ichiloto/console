<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

use InvalidArgumentException;
use ParseError;
use PhpToken;

/**
 * Reads every array literal in a PHP file without evaluating it: returned
 * data, arrays built by helper closures, and arrays passed to calls. Each
 * value keeps its exact byte span, so a literal can be rewritten in place and
 * everything around it stays as the author wrote it.
 */
final class PhpLiteralScanner
{
    /** @var list<PhpToken> */
    private array $tokens = [];

    /**
     * The file's outermost values holding array literals.
     *
     * @return PhpLiteral An expression node whose embedded values are the file's outermost arrays.
     * @throws InvalidArgumentException When the file is not valid PHP.
     */
    public function scanSource(string $source, string $displayPath): PhpLiteral
    {
        try {
            token_get_all($source, TOKEN_PARSE);
        } catch (ParseError $error) {
            throw new InvalidArgumentException(sprintf('%s has invalid PHP at line %d: %s', $displayPath, $error->getLine(), $error->getMessage()), previous: $error);
        }

        $this->tokens = array_values(array_filter(
            PhpToken::tokenize($source),
            static fn(PhpToken $token): bool => ! $token->is([T_WHITESPACE, T_COMMENT, T_DOC_COMMENT, T_OPEN_TAG, T_CLOSE_TAG, T_INLINE_HTML]),
        ));
        $file = new PhpLiteral(PhpLiteral::OTHER, 0, strlen($source), 1, '');
        $file->embedded = $this->collectArrays(0, count($this->tokens));
        foreach ($file->embedded as $array) {
            $array->owner = $file;
        }
        $this->tokens = [];

        return $file;
    }

    /** @return list<PhpLiteral> The array literals in a token range that are not inside another one there. */
    private function collectArrays(int $from, int $to): array
    {
        $arrays = [];

        for ($index = $from; $index < $to; $index++) {
            if ($this->isArrayStart($index)) {
                [$array, $index] = $this->readArray($index);
                $arrays[] = $array;
            }
        }

        return $arrays;
    }

    private function isArrayStart(int $index): bool
    {
        $token = $this->tokens[$index];

        if ($token->is(T_ARRAY)) {
            return ($this->tokens[$index + 1]->text ?? null) === '(';
        }

        if ($token->text !== '[') {
            return false;
        }

        $previous = $this->tokens[$index - 1] ?? null;

        // After a value, `[` indexes it rather than opening an array.
        return $previous === null || ! ($previous->is([T_VARIABLE, T_STRING, T_NAME_QUALIFIED, T_NAME_FULLY_QUALIFIED, T_CONSTANT_ENCAPSED_STRING, T_STATIC])
            || in_array($previous->text, [')', ']', '}'], true));
    }

    /**
     * @return array{PhpLiteral, int} The array and the index of its closing token.
     */
    private function readArray(int $index): array
    {
        $open = $this->tokens[$index]->is(T_ARRAY) ? $index + 1 : $index;
        $closing = $this->findClosing($open);
        $first = $this->tokens[$index];
        $last = $this->tokens[$closing];
        $array = new PhpLiteral(PhpLiteral::ARRAY, $first->pos, $last->pos + strlen($last->text), $first->line, '');
        $nextKey = 0;
        $cursor = $open + 1;

        while ($cursor < $closing) {
            $end = $this->findEntryEnd($cursor, $closing);
            $arrow = $this->findTopLevelArrow($cursor, $end);

            if ($end > $cursor) {
                if ($arrow !== null) {
                    $key = $this->readKey($cursor, $arrow);
                    $value = $this->readValue($arrow + 1, $end);
                } elseif ($this->tokens[$cursor]->is(T_ELLIPSIS)) {
                    $key = null;
                    $value = $this->readValue($cursor + 1, $end);
                } else {
                    $key = $nextKey;
                    $value = $this->readValue($cursor, $end);
                }

                $value->parent = $array;
                $value->parentKey = $key;

                if ($key === null) {
                    $array->embedded[] = $value;
                } else {
                    $array->entries[$key] = $value;
                    if (is_int($key)) {
                        $nextKey = max($nextKey, $key + 1);
                    }
                }
            }

            $cursor = $end + 1;
        }

        return [$array, $closing];
    }

    private function readKey(int $from, int $to): int|string|null
    {
        $key = $this->readValue($from, $to);

        return $key->integer ?? $key->string;
    }

    private function readValue(int $from, int $to): PhpLiteral
    {
        $first = $this->tokens[$from];
        $last = $this->tokens[$to - 1];
        $start = $first->pos;
        $end = $last->pos + strlen($last->text);
        $count = $to - $from;
        $isScalar = ($count === 1 && ($first->is([T_LNUMBER, T_DNUMBER, T_CONSTANT_ENCAPSED_STRING])
                || ($first->is(T_STRING) && in_array(strtolower($first->text), ['true', 'false', 'null'], true))))
            || ($count === 2 && $first->text === '-' && $last->is([T_LNUMBER, T_DNUMBER]));

        if ($isScalar) {
            return new PhpLiteral(PhpLiteral::SCALAR, $start, $end, $first->line, implode('', array_map(
                static fn(PhpToken $token): string => $token->text,
                array_slice($this->tokens, $from, $count),
            )));
        }

        if ($this->isArrayStart($from)) {
            [$array, $closing] = $this->readArray($from);
            if ($closing === $to - 1) {
                return $array;
            }
        }

        $expression = new PhpLiteral(PhpLiteral::OTHER, $start, $end, $first->line, '');
        $expression->embedded = $this->collectArrays($from, $to);
        foreach ($expression->embedded as $array) {
            $array->owner = $expression;
        }

        return $expression;
    }

    private function findClosing(int $open): int
    {
        $depth = 0;
        $count = count($this->tokens);

        for ($index = $open; $index < $count; $index++) {
            $depth += $this->getDepthChange($this->tokens[$index]);
            if ($depth === 0) {
                return $index;
            }
        }

        throw new InvalidArgumentException('An array literal is not closed.');
    }

    /** The index of the `,` ending an entry, or of the array's closing token. */
    private function findEntryEnd(int $from, int $closing): int
    {
        $depth = 0;

        for ($index = $from; $index < $closing; $index++) {
            $token = $this->tokens[$index];
            if ($depth === 0 && $token->text === ',') {
                return $index;
            }
            $depth += $this->getDepthChange($token);
        }

        return $closing;
    }

    private function findTopLevelArrow(int $from, int $to): ?int
    {
        $depth = 0;

        for ($index = $from; $index < $to; $index++) {
            $token = $this->tokens[$index];
            if ($depth === 0 && $token->is(T_DOUBLE_ARROW)) {
                return $index;
            }
            if ($depth === 0 && $token->is([T_FN, T_FUNCTION, T_MATCH])) {
                return null;
            }
            $depth += $this->getDepthChange($token);
        }

        return null;
    }

    private function getDepthChange(PhpToken $token): int
    {
        if ($token->is([T_CURLY_OPEN, T_DOLLAR_OPEN_CURLY_BRACES, T_ATTRIBUTE]) || in_array($token->text, ['(', '[', '{'], true)) {
            return 1;
        }

        return in_array($token->text, [')', ']', '}'], true) ? -1 : 0;
    }
}
