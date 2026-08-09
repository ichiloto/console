<?php

namespace Ichiloto\Console\Commands;

use Ichiloto\Editor\ProjectWorkspace;
use Ichiloto\Editor\Validation\Issue;
use Ichiloto\Editor\Validation\ProjectValidator;
use Ichiloto\Editor\Validation\Severity;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
  name: 'validate',
  description: 'Check a project for content mistakes.',
)]
class ValidateCommand extends Command
{
  public function configure(): void
  {
    $this
      ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
      ->addOption('strict', 's', InputOption::VALUE_NONE, 'Treat warnings as failures too.');
  }

  public function execute(InputInterface $input, OutputInterface $output): int
  {
    $workingDirectory = $input->getOption('directory') ?? getcwd() ?: '.';

    if (is_not_valid_working_dir($workingDirectory)) {
      $output->writeln('<error>The working directory is not valid: ' . $workingDirectory . '</error>');

      return Command::FAILURE;
    }

    $this->bootstrapDependencies($workingDirectory);

    try {
      $workspace = ProjectWorkspace::fromProject($workingDirectory);
      $issues = new ProjectValidator()->validate($workspace);
    } catch (Throwable $throwable) {
      $output->writeln('<error>The project could not be read: ' . $throwable->getMessage() . '</error>');

      return Command::FAILURE;
    }

    $this->report($output, $workspace->projectName, $issues);

    $errors = $this->countOf($issues, Severity::ERROR);
    $warnings = $this->countOf($issues, Severity::WARNING);

    return $errors > 0 || ((bool) $input->getOption('strict') && $warnings > 0)
      ? Command::FAILURE
      : Command::SUCCESS;
  }

  /**
   * Prints what was found, grouped by what it is about.
   *
   * @param OutputInterface $output Where to print.
   * @param string $projectName The project's name.
   * @param Issue[] $issues What was found.
   * @return void
   */
  protected function report(OutputInterface $output, string $projectName, array $issues): void
  {
    $output->writeln('');

    if ($issues === []) {
      $output->writeln("<info>✓</info> {$projectName} looks good.");
      $output->writeln('');

      return;
    }

    $grouped = [];

    foreach ($issues as $issue) {
      $grouped[$issue->where][] = $issue;
    }

    foreach ($grouped as $where => $group) {
      $output->writeln("  <comment>{$where}</comment>");

      foreach ($group as $issue) {
        $marker = $issue->severity === Severity::ERROR ? '<error> ! </error>' : '<comment> ? </comment>';
        $output->writeln("    {$marker} {$issue->message}");

        if ($issue->hint !== '') {
          $output->writeln("        <fg=gray>{$issue->hint}</>");
        }
      }

      $output->writeln('');
    }

    $errors = $this->countOf($issues, Severity::ERROR);
    $warnings = $this->countOf($issues, Severity::WARNING);

    $output->writeln(sprintf(
      '  %d %s, %d %s in %s.',
      $errors,
      $errors === 1 ? 'error' : 'errors',
      $warnings,
      $warnings === 1 ? 'warning' : 'warnings',
      $projectName
    ));
    $output->writeln('');
  }

  /**
   * Counts the issues of one severity.
   *
   * @param Issue[] $issues The issues.
   * @param Severity $severity The severity to count.
   * @return int How many there are.
   */
  protected function countOf(array $issues, Severity $severity): int
  {
    return count(array_filter($issues, static fn(Issue $issue): bool => $issue->severity === $severity));
  }

  /**
   * Loads the editor and the engine, which reading a project needs.
   *
   * @param string $workingDirectory The project directory.
   * @return void
   */
  protected function bootstrapDependencies(string $workingDirectory): void
  {
    $editorAutoloadPath = dirname(__DIR__, 3) . '/editor/vendor/autoload.php';

    if (! class_exists(ProjectWorkspace::class) && is_file($editorAutoloadPath)) {
      require_once $editorAutoloadPath;
    }

    load_engine_autoloader($workingDirectory);
  }
}
