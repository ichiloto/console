<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

use Ichiloto\Engine\Core\ProjectFormat;
use JsonException;
use RuntimeException;

/**
 * The project's ichiloto.json. The format version is written into the
 * author's own file: only the version's bytes change, so indentation, key
 * order and every other value stay as the author left them.
 */
final class ProjectConfigFile
{
    /** @return array<string, mixed> */
    public static function readSettings(string $path): array
    {
        try {
            $data = json_decode((string) file_get_contents($path), true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException $exception) {
            throw new RuntimeException(sprintf('Unable to parse the project configuration at %s: %s', $path, $exception->getMessage()), previous: $exception);
        }

        if (! is_array($data) || array_is_list($data)) {
            throw new RuntimeException(sprintf('The project configuration at %s must contain a JSON object.', $path));
        }

        return $data;
    }

    public static function getFormatVersion(string $path): int
    {
        return ProjectFormat::getVersion(self::readSettings($path)[ProjectFormat::KEY] ?? null);
    }

    /** Records a format version in the file. */
    public static function setFormatVersion(string $path, int $version): void
    {
        new ProjectFileWriter()->writeAtomically($path, self::renderFormatVersion((string) file_get_contents($path), $version));
    }

    /** Returns the configuration source with the format version set, changing nothing else. */
    public static function renderFormatVersion(string $source, int $version): string
    {
        $settings = json_decode($source, true);

        if (! is_array($settings) || array_is_list($settings)) {
            throw new RuntimeException('The project configuration must contain a JSON object.');
        }

        $span = self::findTopLevelValue($source, ProjectFormat::KEY);

        if ($span !== null) {
            $updated = substr($source, 0, $span[0]) . $version . substr($source, $span[1]);
        } else {
            $updated = self::appendMember($source, ProjectFormat::KEY, $version);
        }

        $settings[ProjectFormat::KEY] = $version;

        if (json_decode($updated, true) !== $settings) {
            throw new RuntimeException('The format version could not be recorded without changing other settings.');
        }

        return $updated;
    }

    /**
     * The byte span of a top-level member's value.
     *
     * @return array{int, int}|null
     */
    private static function findTopLevelValue(string $source, string $key): ?array
    {
        $depth = 0;
        $length = strlen($source);
        $expectValueFor = null;

        for ($offset = 0; $offset < $length; $offset++) {
            $character = $source[$offset];

            if ($character === '"') {
                $end = self::findStringEnd($source, $offset);
                $string = json_decode(substr($source, $offset, $end - $offset + 1));

                if ($depth === 1 && $string === $key) {
                    $expectValueFor = $end + 1;
                }

                $offset = $end;
                continue;
            }

            if ($character === ':' && $expectValueFor !== null && $depth === 1) {
                $start = $offset + 1;

                while ($start < $length && ctype_space($source[$start])) {
                    $start++;
                }

                $end = $start;

                while ($end < $length && ! in_array($source[$end], [',', '}', ']'], true) && ! ctype_space($source[$end])) {
                    $end++;
                }

                return [$start, $end];
            }

            if ($character === '{' || $character === '[') {
                $depth++;
            } elseif ($character === '}' || $character === ']') {
                $depth--;
            }

            if ($character === ',') {
                $expectValueFor = null;
            }
        }

        return null;
    }

    private static function findStringEnd(string $source, int $start): int
    {
        $length = strlen($source);

        for ($offset = $start + 1; $offset < $length; $offset++) {
            if ($source[$offset] === '\\') {
                $offset++;
                continue;
            }

            if ($source[$offset] === '"') {
                return $offset;
            }
        }

        throw new RuntimeException('The project configuration has an unterminated string.');
    }

    private static function appendMember(string $source, string $key, int $value): string
    {
        $closing = strrpos($source, '}');

        if ($closing === false) {
            throw new RuntimeException('The project configuration must contain a JSON object.');
        }

        $before = rtrim(substr($source, 0, $closing));
        $indent = preg_match('/\{\s*\n([ \t]+)"/', $source, $matches) === 1 ? $matches[1] : '    ';
        $member = json_encode($key) . ': ' . $value;

        if (str_ends_with($before, '{')) {
            return $before . "\n" . $indent . $member . "\n" . substr($source, $closing);
        }

        return $before . ",\n" . $indent . $member . "\n" . substr($source, $closing);
    }
}
