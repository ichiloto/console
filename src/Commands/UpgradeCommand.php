<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\TerminalInteractivity;
use Ichiloto\Console\Upgrade\GitWorkingTree;
use Ichiloto\Console\Upgrade\ProjectFileWriter;
use Ichiloto\Console\Upgrade\ProjectUpgradeChain;
use Ichiloto\Console\Upgrade\ProjectUpgradeContext;
use Ichiloto\Console\Upgrade\UpgradeReportDocument;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function Laravel\Prompts\confirm;

/**
 * Brings a project to the engine's format: detect the version, explain each
 * pending step, protect uncommitted work, then run the chain and report what
 * needs a person.
 */
#[AsCommand(
    name: 'upgrade',
    description: 'Convert an existing Ichiloto project to the current project format.',
)]
final class UpgradeCommand extends Command
{
    private readonly ProjectUpgradeChain $chain;

    public function __construct(
        ?ProjectUpgradeChain $chain = null,
        private readonly GitWorkingTree $git = new GitWorkingTree(),
        private readonly TerminalInteractivity $terminalInteractivity = new TerminalInteractivity(),
    ) {
        $this->chain = $chain ?? ProjectUpgradeChain::createDefault();
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The existing project directory. Defaults to the current directory.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The permanent vendor/project save identity to use when one is missing.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'List what the upgrade would change without writing files.')
            ->addOption('yes', 'y', InputOption::VALUE_NONE, 'Upgrade without asking for confirmation (required when not interactive).')
            ->addOption('allow-dirty', null, InputOption::VALUE_NONE, 'Upgrade even though the project has uncommitted Git changes.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDirectory = (string) ($input->getOption('directory') ?? getcwd() ?: '.');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $context = new ProjectUpgradeContext(
                $workingDirectory,
                is_string($input->getOption('id')) ? $input->getOption('id') : null,
            );
            $version = $this->chain->getRecordedVersion($context);

            if ($version > $this->chain->currentVersion) {
                $output->writeln(sprintf(
                    '<error>This project uses format %d, which is newer than this Ichiloto reads (%d). Update Ichiloto instead.</error>',
                    $version,
                    $this->chain->currentVersion,
                ));

                return Command::FAILURE;
            }

            if ($version === $this->chain->currentVersion) {
                $output->writeln(sprintf('<info>✓</info> No upgrade is needed; the project is already at format %d.', $version));

                return Command::SUCCESS;
            }

            $plans = $this->chain->createPlans($context);
        } catch (Throwable $throwable) {
            $output->writeln('<error>The project could not be upgraded: ' . OutputFormatter::escape($throwable->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        $output->writeln(sprintf(
            'This project is at format %d; Ichiloto reads format %d. The upgrade will:',
            $version,
            $this->chain->currentVersion,
        ));

        foreach ($plans as $plan) {
            $output->writeln('');
            $output->writeln(sprintf('  <comment>Format %d</comment>, %s:', $plan->targetVersion, $plan->title));

            foreach ($plan->changes as $change) {
                $output->writeln('    - ' . OutputFormatter::escape($change));
            }
        }

        $output->writeln('');

        if ($dryRun) {
            $output->writeln('Dry run only; no files were changed.');

            return Command::SUCCESS;
        }

        $uncommitted = $this->git->getUncommittedChanges($context->root);

        if ($uncommitted !== null && $uncommitted !== [] && ! $input->getOption('allow-dirty')) {
            $output->writeln(sprintf(
                '<error>The project has %d uncommitted %s. Commit or stash them first so the upgrade is one reviewable change, or pass --allow-dirty.</error>',
                count($uncommitted),
                count($uncommitted) === 1 ? 'change' : 'changes',
            ));

            return Command::FAILURE;
        }

        if (! $input->getOption('yes')) {
            if (! $input->isInteractive() || ! $this->terminalInteractivity->supportsPrompts()) {
                $output->writeln('<error>Nothing was changed. Pass --yes to upgrade without a prompt, or --dry-run to only list the changes.</error>');

                return Command::FAILURE;
            }

            if (! confirm('Upgrade the project now?', false)) {
                $output->writeln('Nothing was changed.');

                return Command::SUCCESS;
            }
        }

        try {
            $reports = $this->chain->runPendingSteps($context);
            $document = new UpgradeReportDocument($version, $this->chain->currentVersion, $reports);
            $reportPath = $context->getPath(UpgradeReportDocument::FILENAME);
            new ProjectFileWriter()->writeAtomically($reportPath, $document->renderMarkdown());
        } catch (Throwable $throwable) {
            $reached = $this->chain->getRecordedVersion($context);
            $output->writeln('<error>The upgrade stopped: ' . OutputFormatter::escape($throwable->getMessage()) . '</error>');
            $output->writeln(sprintf('The project is at format %d. Fix the problem and run ichiloto upgrade again to continue.', $reached));

            return Command::FAILURE;
        }

        $output->writeln(sprintf('<info>✓</info> Upgraded the project to format %d.', $this->chain->currentVersion));

        foreach ($document->getFollowUps() as $heading => $sections) {
            $output->writeln('');
            $output->writeln("<comment>{$heading}</comment>");

            foreach ($sections as $section => $items) {
                $output->writeln("  {$section}:");

                foreach ($items as $item) {
                    $output->writeln('    - ' . OutputFormatter::escape($item));
                }
            }
        }

        $output->writeln('');
        $output->writeln('Follow-up list written to ' . $reportPath);

        return Command::SUCCESS;
    }
}
