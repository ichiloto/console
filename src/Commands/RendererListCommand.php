<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Renderer\ProjectRendererAvailability;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\ConsoleOutputInterface;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;

#[AsCommand(name: 'renderer:list', description: 'Read installed renderer availability from a project without launching or updating it.')]
final class RendererListCommand extends Command
{
    public function __construct(private readonly ProjectRendererAvailability $availability = new ProjectRendererAvailability())
    {
        parent::__construct();
    }

    public function configure(): void
    {
        $this
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'Project whose Engine reports renderer availability.')
            ->addOption('json', null, InputOption::VALUE_NONE, 'Return the ichiloto.renderers/1 discovery report.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        try {
            $report = $this->availability->getReport((string) ($input->getOption('directory') ?? getcwd() ?: '.'));
            if ($input->getOption('json')) {
                $output->writeln(json_encode($report, JSON_THROW_ON_ERROR), OutputInterface::OUTPUT_RAW);
                return Command::SUCCESS;
            }
            foreach ($report['renderers'] as $renderer) {
                $output->writeln(sprintf('%s (%s): %s', $renderer['displayName'], $renderer['id'],
                    $renderer['available'] ? 'available' : 'unavailable - ' . $renderer['reason']), OutputInterface::OUTPUT_RAW);
            }
            $output->writeln('Preferred renderer: ' . ($report['preferredId'] ?? 'none'), OutputInterface::OUTPUT_RAW);
            return Command::SUCCESS;
        } catch (Throwable $error) {
            $errors = $output instanceof ConsoleOutputInterface ? $output->getErrorOutput() : $output;
            $errors->writeln('Renderer discovery failed: ' . $error->getMessage(), OutputInterface::OUTPUT_RAW);
            return Command::FAILURE;
        }
    }
}
