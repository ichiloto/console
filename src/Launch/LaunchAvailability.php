<?php

declare(strict_types=1);

namespace Ichiloto\Console\Launch;

/** Which launch targets can open now, why the others cannot, and which one `--renderer=auto` opens. */
final readonly class LaunchAvailability
{
    /**
     * @param array<string, string> $unavailableReasons Each unavailable target's ID and the reason it cannot open.
     * @param string|null $preferredId What `--renderer=auto` opens, or null when nothing is available.
     */
    public function __construct(
        public array $unavailableReasons,
        public ?string $preferredId,
    ) {
    }

    /** Why the target cannot open, or null when it can. */
    public function getUnavailableReason(string $id): ?string
    {
        return $this->unavailableReasons[$id] ?? null;
    }
}
