<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

/** One thing a person needs to do or check after an upgrade step. */
final readonly class ProjectUpgradeFollowUp
{
    /**
     * @param string $section The report heading the item belongs under.
     * @param string $message What needs attention, naming the file and place.
     * @param bool $blocking Whether the project will not load until it is fixed.
     */
    public function __construct(
        public string $section,
        public string $message,
        public bool $blocking = false,
    ) {
    }
}
