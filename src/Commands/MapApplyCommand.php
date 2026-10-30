<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\ChangeOutcomeReport;
use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Editor\Session\ChangeBatch;
use Ichiloto\Editor\Session\InvalidRequest;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Formatter\OutputFormatter;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * Applies a batch of editor session requests to a project as one change set,
 * the way agents and scripts change maps: through the editor, which writes
 * them only when the project they leave is valid. The batch format and the
 * requests are documented in the Editor's guide for agents.
 */
#[AsCommand(
    name: 'map:apply',
    description: 'Apply a batch of editor changes to a project, writing them only when the result is valid.',
)]
final class MapApplyCommand extends Command
{
    public function configure(): void
    {
        $this
            ->addArgument('batch', InputArgument::REQUIRED, 'The batch file: a JSON list of editor session requests.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory.')
            ->addOption('check', null, InputOption::VALUE_NONE, 'Report what committing would find, and write nothing.')
            ->addOption('relocate-saves', null, InputOption::VALUE_NONE, 'Move saves standing where a changed map is now solid to open floor.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Print the outcome as JSON.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = (string) ($input->getOption('directory') ?? getcwd() ?: '.');

        if (is_not_valid_working_dir($directory)) {
            $output->writeln('<error>The working directory is not valid: ' . OutputFormatter::escape($directory) . '</error>');

            return Command::FAILURE;
        }
        $directory = realpath($directory) ?: $directory;

        try {
            $changes = ChangeBatch::readFile((string) $input->getArgument('batch'));
        } catch (InvalidRequest $invalid) {
            $output->writeln('<error>' . OutputFormatter::escape($invalid->getMessage()) . '</error>');

            return Command::FAILURE;
        }

        EditorProjectBootstrap::prepare($directory);
        $outcome = ChangeBatch::apply($directory, $changes, (bool) $input->getOption('relocate-saves'), (bool) $input->getOption('check'));

        if ($input->getOption('json')) {
            $output->writeln((string) json_encode($outcome, JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE));
        } else {
            ChangeOutcomeReport::write($output, $outcome);
        }

        return $outcome['status'] === 'saved' || ($outcome['status'] === 'checked' && $outcome['blocking'] === 0)
            ? Command::SUCCESS
            : Command::FAILURE;
    }
}
