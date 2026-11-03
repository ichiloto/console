<?php

declare(strict_types=1);

namespace Ichiloto\Console\Launch;

use RuntimeException;

/**
 * The choices a launching command offers: the renderers `play` and `battle`
 * run in, or the editors `edit` opens. Their IDs are the renderer IDs that
 * `--renderer` accepts, so `--renderer=gpui` and `--gui` mean the same
 * graphical choice in every command.
 */
interface LaunchTargetSource
{
    /** The question the interactive list asks, such as "Select renderer". */
    public string $promptLabel { get; }

    /**
     * Every choice, in list order, without checking whether it can open.
     *
     * @return array<string, string> Each target's ID and display name.
     */
    public function getTargetNames(): array;

    /**
     * Checks which choices can open for the project.
     *
     * @throws RuntimeException When availability cannot be read.
     */
    public function readAvailability(string $projectDirectory): LaunchAvailability;
}
