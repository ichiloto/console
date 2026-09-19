<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\RendererPackageInstaller;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(
    name: 'renderer:install',
    description: 'Install a verified renderer package into a project\'s Engine.',
)]
final class RendererInstallCommand extends Command
{
    public function __construct(private readonly RendererPackageInstaller $installer = new RendererPackageInstaller())
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addArgument('package', InputArgument::REQUIRED, 'A renderer package: a .tar.gz archive or an extracted package directory.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project whose installed Engine receives the renderer.')
            ->addOption('engine', null, InputOption::VALUE_REQUIRED, 'Install directly into this Engine checkout instead of a project\'s Engine package.')
            ->addOption('dry-run', null, InputOption::VALUE_NONE, 'Verify the package and report the installation without writing files.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $package = (string) $input->getArgument('package');
        $engineOption = $input->getOption('engine');
        $dryRun = (bool) $input->getOption('dry-run');

        try {
            $boundary = is_string($engineOption) && $engineOption !== ''
                ? $this->installer->resolveEngineBoundary($engineOption)
                : $this->installer->resolveProjectBoundary((string) ($input->getOption('directory') ?? getcwd() ?: '.'));

            $result = $this->installer->install($package, $boundary, $dryRun);
        } catch (Throwable $throwable) {
            $output->writeln('<error>The renderer package could not be installed: ' . $throwable->getMessage() . '</error>');

            return Command::FAILURE;
        }

        $verb = $result['dryRun'] ? 'Would install' : 'Installed';
        $version = $result['packageVersion'] !== '' ? ' ' . $result['packageVersion'] : '';

        $output->writeln(sprintf(
            '<info>✓</info> %s renderer <comment>%s</comment>%s for <comment>%s</comment> (%d verified files).',
            $verb,
            $result['renderer'],
            $version,
            $result['platform'],
            $result['fileCount'],
        ));
        $output->writeln(sprintf('  Boundary: %s', $result['installedDirectory']));

        if ($result['backupDirectory'] !== null) {
            $output->writeln(sprintf(
                '  %s backed up to %s',
                $result['dryRun'] ? 'The existing installation would be' : 'The existing installation was',
                $result['backupDirectory'],
            ));
        }

        if ($result['platform'] !== $result['hostPlatform']) {
            $output->writeln(sprintf(
                '<comment>Note: the package targets %s, but this host is %s; the Engine will not select it here.</comment>',
                $result['platform'],
                $result['hostPlatform'],
            ));
        }

        if ($result['dryRun']) {
            $output->writeln('Dry run only; no files were changed.');
        } else {
            $output->writeln(sprintf(
                'Launch with <comment>ichiloto play --renderer=%s</comment> once the Engine registers that renderer.',
                $result['renderer'],
            ));
        }

        return Command::SUCCESS;
    }
}
