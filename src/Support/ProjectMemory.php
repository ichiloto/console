<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

/**
 * The memory an Ichiloto project process works in. The game, its startup
 * checks and the terminal session run in their own PHP processes at this
 * limit; a command that opens a whole project in this process (the editor's
 * change sets, conversion and validation) raises its own limit to match,
 * since one large world map validated twice outgrows PHP's default 128M.
 */
final class ProjectMemory
{
    public const string LIMIT = '512M';

    /** Raises this process's memory limit to {@see LIMIT}, keeping a higher or unlimited one. */
    public static function raise(): void
    {
        $current = self::toBytes((string) ini_get('memory_limit'));
        if ($current !== -1 && $current < self::toBytes(self::LIMIT)) {
            ini_set('memory_limit', self::LIMIT);
        }
    }

    /** A php.ini memory value in bytes, -1 for unlimited. */
    private static function toBytes(string $value): int
    {
        $value = trim($value);
        if ($value === '' || $value === '-1') {
            return -1;
        }
        $number = (int) $value;

        return match (strtoupper(substr($value, -1))) {
            'G' => $number * 1024 ** 3,
            'M' => $number * 1024 ** 2,
            'K' => $number * 1024,
            default => $number,
        };
    }
}
