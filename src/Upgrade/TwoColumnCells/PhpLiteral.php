<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade\TwoColumnCells;

/**
 * One value written in a PHP source file, with the exact bytes it occupies:
 * an array literal with its entries, a scalar literal, or any other
 * expression together with the array literals written inside it (in a call's
 * arguments or a closure's body, for example).
 */
final class PhpLiteral
{
    public const string ARRAY = 'array';
    public const string SCALAR = 'scalar';
    public const string OTHER = 'other';

    /** @var array<int|string, PhpLiteral> Entries of an array by literal key, in source order. */
    public array $entries = [];
    /** @var list<PhpLiteral> Array literals written inside an expression. */
    public array $embedded = [];
    /** The array holding this value, or null for an array written inside an expression or at file level. */
    public ?PhpLiteral $parent = null;
    /** The key this value has in its parent array; null when it has none or it could not be read. */
    public int|string|null $parentKey = null;
    /** The expression an array is written inside, when it has no parent array. */
    public ?PhpLiteral $owner = null;

    public function __construct(
        public readonly string $kind,
        public readonly int $start,
        public readonly int $end,
        public readonly int $line,
        public readonly string $text,
    ) {
    }

    /** The value of an integer literal, or null for anything else. */
    public ?int $integer {
        get {
            if ($this->kind !== self::SCALAR) {
                return null;
            }
            $text = str_replace([' ', "\t", "\n", "\r", '_'], '', $this->text);
            $negative = str_starts_with($text, '-');
            $digits = ltrim($text, '-');
            if (preg_match('/\A(?:0[xX][0-9a-fA-F]+|0[bB][01]+|0[oO]?[0-7]*|[1-9][0-9]*)\z/', $digits) !== 1) {
                return null;
            }
            $value = intval(str_replace(['0o', '0O'], '0', $digits), 0);
            return $negative ? -$value : $value;
        }
    }

    /** The value of a plain string literal, or null for anything else. */
    public ?string $string {
        get {
            if ($this->kind !== self::SCALAR || strlen($this->text) < 2) {
                return null;
            }
            $quote = $this->text[0];
            $body = substr($this->text, 1, -1);
            return match ($quote) {
                "'" => strtr($body, ['\\\\' => '\\', "\\'" => "'"]),
                '"' => stripcslashes($body),
                default => null,
            };
        }
    }

    /** The value of `true` or `false`, or null for anything else. */
    public ?bool $boolean {
        get => $this->kind === self::SCALAR ? match (strtolower($this->text)) {
            'true' => true,
            'false' => false,
            default => null,
        } : null;
    }

    public function getEntry(int|string $key): ?PhpLiteral
    {
        return $this->entries[$key] ?? null;
    }

    /** The string literal held under a key, or null. */
    public function getString(int|string $key): ?string
    {
        return $this->getEntry($key)?->string;
    }

    /** Every array literal in this value and below it, this one included when it is an array. */
    public function getArrays(): \Generator
    {
        if ($this->kind === self::ARRAY) {
            yield $this;
        }
        foreach ($this->entries as $entry) {
            yield from $entry->getArrays();
        }
        foreach ($this->embedded as $array) {
            yield from $array->getArrays();
        }
    }

    /**
     * The array this value belongs to: its parent, or for an array written
     * inside an expression, the array holding that expression.
     */
    public ?PhpLiteral $enclosingArray {
        get {
            for ($node = $this; $node->parent === null; $node = $node->owner) {
                if ($node->owner === null) {
                    return null;
                }
            }

            return $node->parent;
        }
    }

    /** The value in its enclosing array that this value is, or is written inside. */
    public PhpLiteral $enclosedValue {
        get {
            $node = $this;
            while ($node->parent === null && $node->owner !== null) {
                $node = $node->owner;
            }

            return $node;
        }
    }

    /** Whether this value sits, at any depth, under one of the keys given. */
    public function isUnderKey(string ...$keys): bool
    {
        for ($node = $this; $node !== null; $node = $node->enclosedValue->parent) {
            $value = $node->enclosedValue;
            if (is_string($value->parentKey) && in_array($value->parentKey, $keys, true)) {
                return true;
            }
            if ($value->parent === null) {
                return false;
            }
        }

        return false;
    }

    /**
     * A readable path such as `npcs[2].wanderArea`, from the outermost array
     * this value belongs to. An element whose key cannot be read, such as a
     * spread, is shown as `[?]`.
     */
    public function getPath(): string
    {
        $parts = [];
        for ($node = $this->enclosedValue; $node->parent !== null; $node = $node->parent->enclosedValue) {
            $parts[] = match (true) {
                is_int($node->parentKey) => "[{$node->parentKey}]",
                $node->parentKey === null => '[?]',
                default => '.' . $node->parentKey,
            };
        }

        return ltrim(implode('', array_reverse($parts)), '.');
    }
}
