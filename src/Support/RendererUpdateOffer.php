<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Closure;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

use function Laravel\Prompts\select;

/**
 * Offers a newer renderer before a launch: update now, continue with the
 * installed one, or skip that version for good. A launch never waits on a
 * failed check or update, and a session that cannot prompt only says how to
 * update later.
 */
final readonly class RendererUpdateOffer
{
  private SourceRendererUpdateChecker $checker;

  private SourceRendererUpdater $updater;

  private TerminalInteractivity $terminalInteractivity;

  private Closure $prompt;

  /**
   * @param (callable(string, array<string, string>): (int|string))|null $prompt Chooses among the offer's options.
   */
  public function __construct(
    ?SourceRendererUpdateChecker $checker = null,
    ?SourceRendererUpdater $updater = null,
    ?TerminalInteractivity $terminalInteractivity = null,
    ?callable $prompt = null,
  ) {
    $this->checker = $checker ?? new SourceRendererUpdateChecker();
    $this->updater = $updater ?? new SourceRendererUpdater($this->checker);
    $this->terminalInteractivity = $terminalInteractivity ?? new TerminalInteractivity();
    $this->prompt = $prompt === null
      ? static fn (string $label, array $options): int|string => select(label: $label, options: $options)
      : Closure::fromCallable($prompt);
  }

  public function offer(string $workingDirectory, string $rendererId, InputInterface $input, OutputInterface $output): void
  {
    try {
      $update = $this->checker->check($workingDirectory, $rendererId);
      if ($update === null || $update->current || $update->skipped) { return; }
      $output->writeln('<info>A ' . $rendererId . ' renderer update is available for ' . $update->platform . '.</info>');
      if (! $input->isInteractive() || ! $this->terminalInteractivity->supportsPrompts()) {
        $output->writeln('Continuing game launch with the selected renderer. Run `ichiloto renderer:update` to update it.');
        return;
      }
      $choice = ($this->prompt)('Renderer update available', [
        'update' => 'Update now',
        'continue' => 'Continue this launch',
        'skip' => 'Skip this version',
      ]);
      if ($choice === 'update') {
        $installed = $this->updater->update($workingDirectory, $rendererId, $output);
        $output->writeln($installed ? '<info>Renderer updated.</info>' : '<info>Renderer is already current.</info>');
      } elseif ($choice === 'skip') {
        $this->updater->skip($update);
        $output->writeln('This renderer source version will not be offered again.');
      } elseif ($choice !== 'continue') {
        throw new \UnexpectedValueException('The renderer update choice was not recognized.');
      }
    } catch (Throwable $error) {
      $output->writeln('<comment>Renderer update check or update failed: '
        . OutputFormatter::escape($error->getMessage()) . '. Continuing game launch.</comment>');
    }
  }
}
