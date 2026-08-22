<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

/** Canonical baseline save metadata shared by new and upgraded projects. */
final class SaveCompatibilityMetadata
{
    /**
     * @return array{
     *   contentVersion: int,
     *   migrations: array<never, never>,
     *   aliases: array<never, never>,
     *   tombstones: array<never, never>
     * }
     */
    public static function baseline(): array
    {
        return [
            'contentVersion' => 0,
            'migrations' => [],
            'aliases' => [],
            'tombstones' => [],
        ];
    }

    public static function renderBaseline(): string
    {
        return <<<'PHP'
<?php

return [
  'contentVersion' => 0,
  'migrations' => [],
  'aliases' => [],
  'tombstones' => [],
];
PHP . "\n";
    }
}
