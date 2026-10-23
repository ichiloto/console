<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * How the commands that change a project through the editor's change sets
 * ({@see \Ichiloto\Editor\Session\ChangeBatch}) say what happened: what
 * was saved, checked or refused, the documents written, what validation
 * found and the saves moved.
 */
final class ChangeOutcomeReport
{
    private function __construct()
    {
    }

    /**
     * Prints what a change set did and what validation found.
     *
     * @param array<string, mixed> $outcome
     */
    public static function write(OutputInterface $output, array $outcome): void
    {
        $output->writeln('');
        $output->writeln(match ($outcome['status']) {
            'saved' => sprintf('<info>Saved</info> %s.', implode(', ', $outcome['saved'])
                ?: (($outcome['written'] ?? []) === [] ? 'nothing; nothing changed' : 'no map; the documents below changed')),
            'checked' => sprintf('<info>Checked</info> %s; nothing was written.', implode(', ', $outcome['maps']) ?: 'no map changed'),
            'refused' => sprintf('<error> Refused </error> %s Nothing was written.', OutputFormatter::escape((string) ($outcome['reason'] ?? ''))),
            default => sprintf('<error> Failed </error> %s%s Nothing was written.',
                $outcome['step'] === null ? '' : sprintf('Change %d (%s): ', $outcome['step'], $outcome['method'] ?? '?'),
                OutputFormatter::escape((string) $outcome['message'])),
        });

        // A batch may change databases and cutscenes as well as maps; each written document is named.
        foreach ($outcome['written'] ?? [] as $document => $paths) {
            $output->writeln(sprintf('  <fg=gray>wrote %s: %s</>', OutputFormatter::escape((string) $document),
                OutputFormatter::escape(implode(', ', (array) $paths))));
        }
        foreach ($outcome['issues'] ?? [] as $issue) {
            $marker = $issue['severity'] === 'error' ? '<error> ! </error>' : '<comment> ? </comment>';
            $output->writeln(sprintf('  %s %s: %s', $marker, OutputFormatter::escape((string) ($issue['map'] ?? $issue['where'])),
                OutputFormatter::escape($issue['message'])));
            if ($issue['hint'] !== '') {
                $output->writeln('        <fg=gray>' . OutputFormatter::escape($issue['hint']) . '</>');
            }
        }
        foreach ($outcome['relocated'] ?? [] as $move) {
            $output->writeln('  ' . OutputFormatter::escape($move));
        }
        $output->writeln('');
    }
}
