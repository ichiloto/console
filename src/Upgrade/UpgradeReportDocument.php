<?php

declare(strict_types=1);

namespace Ichiloto\Console\Upgrade;

/**
 * The follow-up list an upgrade leaves in the project, for a person to work
 * through: per step, what changed and what needs attention, blocking items
 * first.
 */
final readonly class UpgradeReportDocument
{
    public const string FILENAME = 'ichiloto-upgrade-report.md';

    /** @param list<ProjectUpgradeReport> $reports */
    public function __construct(
        private int $fromVersion,
        private int $toVersion,
        private array $reports,
    ) {
    }

    /**
     * The follow-up items grouped by step and section, blocking sections first.
     *
     * @return array<string, array<string, list<string>>> Items by section, by step heading.
     */
    public function getFollowUps(): array
    {
        $steps = [];

        foreach ($this->reports as $report) {
            $blocking = [];
            $other = [];

            foreach ($report->followUps as $item) {
                if ($item->blocking) {
                    $blocking[$item->section][] = $item->message;
                } else {
                    $other[$item->section][] = $item->message;
                }
            }

            $steps[$this->getStepHeading($report)] = $blocking + $other;
        }

        return $steps;
    }

    public function renderMarkdown(): string
    {
        $lines = [
            '# Ichiloto upgrade report',
            '',
            sprintf('This project was upgraded from format %d to format %d. Work through the items below, then run `ichiloto validate`.', $this->fromVersion, $this->toVersion),
        ];
        $followUps = $this->getFollowUps();

        foreach ($this->reports as $report) {
            $heading = $this->getStepHeading($report);
            $lines[] = '';
            $lines[] = "## {$heading}";
            $lines[] = '';
            $lines[] = 'Changed:';
            $lines[] = '';

            foreach ($report->changes as $change) {
                $lines[] = "- {$change}";
            }

            foreach ($followUps[$heading] ?? [] as $section => $items) {
                $lines[] = '';
                $lines[] = "### {$section}";
                $lines[] = '';

                foreach ($items as $item) {
                    $lines[] = "- {$item}";
                }
            }
        }

        return implode("\n", $lines) . "\n";
    }

    private function getStepHeading(ProjectUpgradeReport $report): string
    {
        return sprintf('Format %d: %s', $report->targetVersion, $report->title);
    }
}
