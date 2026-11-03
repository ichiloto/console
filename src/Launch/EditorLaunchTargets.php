<?php

declare(strict_types=1);

namespace Ichiloto\Console\Launch;

use Ichiloto\Console\Support\GuiEditorLocator;

/**
 * The editors `edit` opens a project in: the Terminal editor, always
 * available, and the GPUI editor, available once it is built
 * (`GuiEditorLocator`). `--renderer=auto` prefers the GPUI editor.
 */
final readonly class EditorLaunchTargets implements LaunchTargetSource
{
    public function __construct(
        private GuiEditorLocator $locator,
        public string $promptLabel = 'Select editor',
    ) {
    }

    public function getTargetNames(): array
    {
        return [
            LaunchRequest::TERMINAL_ID => 'Terminal editor',
            LaunchRequest::GRAPHICAL_ID => 'GPUI editor',
        ];
    }

    public function readAvailability(string $projectDirectory): LaunchAvailability
    {
        if ($this->locator->locate() === null) {
            return new LaunchAvailability([LaunchRequest::GRAPHICAL_ID => $this->locator->describeMissing()], LaunchRequest::TERMINAL_ID);
        }

        return new LaunchAvailability([], LaunchRequest::GRAPHICAL_ID);
    }
}
