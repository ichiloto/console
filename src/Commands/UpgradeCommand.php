<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\LegacyProjectUpgrader;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'upgrade',
    description: 'Add current mandatory metadata to an existing Ichiloto project.',
)]
final class UpgradeCommand extends Command
{
    public function configure(): void
    {
        $this
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The existing project directory.')
            ->addOption('id', null, InputOption::VALUE_REQUIRED, 'The permanent vendor/project save identity to use when one is missing.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Report the upgrade without writing files.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $workingDirectory = (string) ($input->getOption('directory') ?? getcwd() ?: '.');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $result = new LegacyProjectUpgrader()->upgrade(
                $workingDirectory,
                is_string($input->getOption('id')) ? $input->getOption('id') : null,
                $dryRun,
            );
        } catch (Throwable $throwable) {
            $output->writeln('<error>The project could not be upgraded: ' . $throwable->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $verb = $dryRun ? 'Would add' : 'Added';

        if ($result['configChanged']) {
            $output->writeln(sprintf(
                '<info>✓</info> %s stable project id <comment>%s</comment> to ichiloto.json.',
                $verb,
                $result['projectId'],
            ));
        }

        if ($result['manifestChanged']) {
            $output->writeln(sprintf(
                '<info>✓</info> %s assets/Data/save-compatibility.php at legacy content version 0.',
                $dryRun ? 'Would create' : 'Created',
            ));
        }

        if (! $result['configChanged'] && ! $result['manifestChanged']) {
            $output->writeln('<info>✓</info> No upgrade is needed; mandatory save metadata is already present.');
        }

        $output->writeln(sprintf(
            '<comment>Keep project id %s unchanged once saves exist.</comment>',
            $result['projectId'],
        ));

        if ($dryRun) {
            $output->writeln('Dry run only; no files were changed.');
        } else {
            $output->writeln('Run <comment>ichiloto validate</comment> to check the upgraded project.');
        }

        return Command::SUCCESS;
    }
}
