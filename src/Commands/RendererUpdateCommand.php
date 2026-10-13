<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\SourceRendererUpdater;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'renderer:update', description: 'Build and install a declared development renderer on request.')]
final class RendererUpdateCommand extends Command
{
    public function __construct(private readonly SourceRendererUpdater $updater = new SourceRendererUpdater())
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addArgument('renderer', InputArgument::OPTIONAL, 'Renderer ID to update.', 'gpui')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Project whose Engine declares the renderer source.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getOption('directory') ?? getcwd();
        $renderer = $input->getArgument('renderer');
        if (! is_string($directory) || ! is_dir($directory) || ! is_string($renderer)) {
            $output->writeln('<error>Supply a valid project directory and renderer ID.</error>');
            return Command::INVALID;
        }
        $project = realpath($directory);
        if ($project === false || ! is_file($project . '/vendor/autoload.php')) {
            $output->writeln('<error>Project dependencies are not installed in ' . OutputFormatter::escape($directory) . '.</error>');
            return Command::FAILURE;
        }
        try {
            $installed = $this->updater->update($project, $renderer, $output);
        } catch (Throwable $error) {
            $output->writeln('<error>Renderer update failed: ' . OutputFormatter::escape($error->getMessage()) . '</error>');
            return Command::FAILURE;
        }
        $output->writeln($installed ? '<info>Renderer updated.</info>' : '<info>Renderer is already current.</info>');
        return Command::SUCCESS;
    }
}
