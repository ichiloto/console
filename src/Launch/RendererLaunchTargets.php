<?php

declare(strict_types=1);

namespace Ichiloto\Console\Launch;

use Ichiloto\Console\Renderer\ProjectRendererAvailability;
use Ichiloto\Console\Renderer\RendererRegistry;

/**
 * The renderers `play` and `battle` run a project in: the Console's
 * registered renderers, available as the project's own Engine reports them.
 */
final readonly class RendererLaunchTargets implements LaunchTargetSource
{
    public function __construct(
        private RendererRegistry $registry = new RendererRegistry(),
        private ProjectRendererAvailability $availability = new ProjectRendererAvailability(),
        public string $promptLabel = 'Select renderer',
    ) {
    }

    public function getTargetNames(): array
    {
        return $this->registry->options();
    }

    public function readAvailability(string $projectDirectory): LaunchAvailability
    {
        return $this->readReport($this->availability->getReport($projectDirectory));
    }

    /**
     * Reads a validated `ichiloto.renderers/1` report for the registered
     * renderers; a registered renderer the report leaves out is unavailable.
     *
     * @param array{schema: string, renderers: list<array{id: string, displayName: string, available: bool, reason: ?string}>, preferredId: ?string} $report
     */
    public function readReport(array $report): LaunchAvailability
    {
        $reported = array_column($report['renderers'], null, 'id');
        $reasons = [];

        foreach ($this->registry->all() as $renderer) {
            $entry = $reported[$renderer->id] ?? null;

            if ($entry === null) {
                $reasons[$renderer->id] = sprintf('The project\'s Engine does not offer the %s renderer.', $renderer->displayName);
            } elseif (! $entry['available']) {
                $reasons[$renderer->id] = (string) $entry['reason'];
            }
        }

        return new LaunchAvailability($reasons, $report['preferredId']);
    }
}
