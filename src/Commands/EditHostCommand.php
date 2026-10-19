<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\EditorProjectBootstrap;
use Ichiloto\Editor\Session\SessionHost;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;

/**
 * The editor session host: the GUI editor starts it as its child process
 * (`ichiloto edit --gui`), and an agent or script drives it to change maps
 * through the editor. It speaks the session protocol on standard input and
 * output, so it is not a command to type by hand; the Editor's guide
 * "Change Maps From A Script" documents it, and `map:apply` runs a batch of
 * its requests in one go.
 */
#[AsCommand(
    name: 'edit:host',
    description: 'Serve a project to the GUI editor or a script over standard input and output.',
    hidden: true,
)]
final class EditHostCommand extends Command
{
    public function configure(): void
    {
        $this->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The project directory to serve.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $directory = $input->getOption('directory') ?? getcwd() ?: '.';

        if (is_not_valid_working_dir($directory)) {
            fwrite(STDERR, 'The working directory is not valid: ' . $directory . PHP_EOL);

            return Command::FAILURE;
        }

        EditorProjectBootstrap::prepare($directory);
        (new SessionHost(STDIN, STDOUT, STDERR))->run();

        return Command::SUCCESS;
    }
}
