<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\NewProjectScaffolder;
use RuntimeException;
use Symfony\Component\Console\Attribute\AsCommand;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputArgument;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;
use Symfony\Component\Console\Output\OutputInterface;
use Throwable;
use function Laravel\Prompts\confirm;
use function Laravel\Prompts\error;
use function Laravel\Prompts\info;
use function Laravel\Prompts\intro;
use function Laravel\Prompts\note;
use function Laravel\Prompts\outro;
use function Laravel\Prompts\select;
use function Laravel\Prompts\spin;
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

#[AsCommand(
    name: 'new',
    description: 'Forge a brand new Ichiloto project.',
)]
final class NewCommand extends Command
{
    private const string DEFAULT_HERO_NAME = 'Hero';

    public function configure(): void
    {
        $this
            ->addArgument('name', InputArgument::OPTIONAL, 'The name of the project to create.')
            ->addOption('directory', 'd', InputOption::VALUE_REQUIRED, 'The directory where the project should be forged.')
            ->addOption('hero', null, InputOption::VALUE_REQUIRED, 'The name of the first party member.')
            ->addOption('battle-engine', null, InputOption::VALUE_REQUIRED, 'The battle engine to seed (traditional or active_time).')
            ->addOption('install', null, InputOption::VALUE_NONE, 'Install Composer dependencies after scaffolding.')
            ->addOption('no-install', null, InputOption::VALUE_NONE, 'Skip Composer dependency installation.');
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isInteractive = $input->isInteractive();
        $scaffolder = new NewProjectScaffolder();

        try {
            if ((bool) $input->getOption('install') && (bool) $input->getOption('no-install')) {
                throw new RuntimeException('Choose either --install or --no-install, not both.');
            }

            if ($isInteractive) {
                intro('The guild cartographers are ready. Let us forge a fresh realm for your next terminal-born legend.');
            }

            $projectLabel = $this->resolveProjectLabel($input, $isInteractive);
            $directoryName = $this->slugify($projectLabel);

            if ($directoryName === '') {
                throw new RuntimeException('The project name must contain at least one letter or number.');
            }

            $displayName = $this->titleize($projectLabel);
            $targetDirectory = $this->resolveTargetDirectory($input, $isInteractive, $directoryName);
            $heroName = $this->resolveHeroName($input, $isInteractive);
            $battleEngine = $this->resolveBattleEngine($input, $isInteractive);
            $composerBinary = $this->findComposerBinary();
            $shouldInstall = $this->resolveInstallPreference($input, $isInteractive, $composerBinary !== null);

            $heroId = $this->toIdentifier($heroName);

            if ($heroId === '') {
                throw new RuntimeException('The first hero needs a valid name.');
            }

            $scaffolder->ensureTargetIsAvailable($targetDirectory);

            if ($isInteractive) {
                table(
                    ['Quest', 'Destination', 'Vanguard', 'Battle Rhythm', 'Provision Supplies'],
                    [[
                        $displayName,
                        $targetDirectory,
                        $heroName,
                        $battleEngine === 'active_time' ? 'Active Time' : 'Traditional',
                        $shouldInstall ? 'Yes' : 'Later',
                    ]]
                );

                note('A starter realm, a first hero, and the baseline archives needed by the editor and runtime will be prepared for you.');

                if (! confirm('Shall we open the gates and begin this quest?', true, 'Forge it', 'Not yet')) {
                    warning('No worries. The realm can wait until you are ready to summon it.');
                    return Command::SUCCESS;
                }
            }

            $result = $this->runTask($isInteractive, fn() => $scaffolder->scaffold([
                'displayName' => $displayName,
                'directoryName' => $directoryName,
                'targetDirectory' => $targetDirectory,
                'heroName' => $heroName,
                'heroId' => $heroId,
                'battleEngine' => $battleEngine,
            ]), 'Forging maps, ledgers, and legends...');

            $installFailed = false;
            $installOutput = null;

            if ($shouldInstall) {
                if ($composerBinary === null) {
                    $installFailed = true;
                    $installOutput = 'Composer was not found on your PATH.';
                } else {
                    $installResult = $this->runTask(
                        $isInteractive,
                        fn() => $this->installDependencies($composerBinary, $targetDirectory),
                        'Provisioning the engine and supplies with Composer...'
                    );
                    $installFailed = ! $installResult['success'];
                    $installOutput = $installResult['output'];
                }
            }

            $this->renderSuccessSummary(
                output: $output,
                isInteractive: $isInteractive,
                displayName: $displayName,
                targetDirectory: $targetDirectory,
                fileCount: count($result['files']),
                dependencyState: $shouldInstall
                    ? ($installFailed ? 'failed' : 'installed')
                    : 'skipped',
                installOutput: $installOutput,
            );

            if ($installFailed && (bool) $input->getOption('install')) {
                return Command::FAILURE;
            }
        } catch (Throwable $throwable) {
            if ($isInteractive) {
                error($throwable->getMessage());
            } else {
                $output->writeln(sprintf('<error>%s</error>', $throwable->getMessage()));
            }

            return Command::FAILURE;
        }

        return Command::SUCCESS;
    }

    private function resolveProjectLabel(InputInterface $input, bool $isInteractive): string
    {
        $projectName = trim((string) ($input->getArgument('name') ?? ''));

        if ($projectName !== '') {
            return $projectName;
        }

        if (! $isInteractive) {
            throw new RuntimeException('Please provide a project name, for example: ichiloto new my-rpg');
        }

        return trim(text(
            label: 'What shall this adventure be called?',
            placeholder: 'Moonfall Legend',
            default: 'my-rpg',
            required: true,
            validate: fn(string $value): ?string => $this->slugify($value) === ''
                ? 'Choose a name with at least one letter or number.'
                : null,
            hint: 'You can use a title or a slug. The directory name will be forged from it.',
        ));
    }

    private function resolveTargetDirectory(InputInterface $input, bool $isInteractive, string $directoryName): string
    {
        $providedDirectory = trim((string) ($input->getOption('directory') ?? ''));
        $defaultDirectory = $this->normalizePath((getcwd() ?: '.') . DIRECTORY_SEPARATOR . $directoryName);

        if ($providedDirectory !== '') {
            return $this->normalizePath($providedDirectory);
        }

        if (! $isInteractive) {
            return $defaultDirectory;
        }

        return $this->normalizePath(text(
            label: 'Where should the realm be forged?',
            placeholder: $defaultDirectory,
            default: $defaultDirectory,
            required: true,
            validate: static fn(string $value): ?string => trim($value) === '' ? 'Choose a destination directory.' : null,
            hint: 'Existing non-empty directories will be protected.',
        ));
    }

    private function resolveHeroName(InputInterface $input, bool $isInteractive): string
    {
        $heroName = trim((string) ($input->getOption('hero') ?? ''));

        if ($heroName !== '') {
            return $heroName;
        }

        if (! $isInteractive) {
            return self::DEFAULT_HERO_NAME;
        }

        return trim(text(
            label: 'Who leads the first party into the unknown?',
            placeholder: 'Arin',
            default: self::DEFAULT_HERO_NAME,
            required: true,
            validate: fn(string $value): ?string => $this->toIdentifier($value) === ''
                ? 'Your vanguard needs a usable name.'
                : null,
            hint: 'This creates the first actor in assets/Data/Actors.',
        ));
    }

    private function resolveBattleEngine(InputInterface $input, bool $isInteractive): string
    {
        $provided = trim((string) ($input->getOption('battle-engine') ?? ''));

        if ($provided !== '') {
            return $this->normalizeBattleEngine($provided);
        }

        if (! $isInteractive) {
            return 'traditional';
        }

        return select(
            label: 'Choose your battle rhythm',
            options: [
                'traditional' => 'Traditional turn-based',
                'active_time' => 'Active Time Battle',
            ],
            default: 'traditional',
            hint: 'You can change this later in the editor.',
            info: 'Traditional is steady and classic. Active Time keeps the pressure on.',
        );
    }

    private function resolveInstallPreference(InputInterface $input, bool $isInteractive, bool $composerAvailable): bool
    {
        if ((bool) $input->getOption('install')) {
            return true;
        }

        if ((bool) $input->getOption('no-install')) {
            return false;
        }

        if (! $isInteractive || ! $composerAvailable) {
            return false;
        }

        return confirm(
            label: 'Provision the engine and dependencies with Composer now?',
            default: true,
            yes: 'Provision now',
            no: 'Later',
            hint: 'Choosing later still creates the project and tells you the next command to run.',
        );
    }

    /**
     * @return array{success: bool, output: string}
     */
    private function installDependencies(string $composerBinary, string $targetDirectory): array
    {
        $command = sprintf(
            '%s install --working-dir=%s --no-interaction 2>&1',
            escapeshellarg($composerBinary),
            escapeshellarg($targetDirectory),
        );

        exec($command, $lines, $exitCode);

        return [
            'success' => $exitCode === 0,
            'output' => trim(implode(PHP_EOL, $lines)),
        ];
    }

    private function renderSuccessSummary(
        OutputInterface $output,
        bool $isInteractive,
        string $displayName,
        string $targetDirectory,
        int $fileCount,
        string $dependencyState,
        ?string $installOutput,
    ): void {
        $nextSteps = match ($dependencyState) {
            'installed' => [
                sprintf('cd %s', $this->shellPath($targetDirectory)),
                'ichiloto edit',
                'ichiloto play',
            ],
            default => [
                sprintf('cd %s', $this->shellPath($targetDirectory)),
                'composer install',
                'ichiloto edit',
                'ichiloto play',
            ],
        };

        if ($isInteractive) {
            info(sprintf('%s has been forged. %d files were prepared for your opening chapter.', $displayName, $fileCount));

            if ($dependencyState === 'installed') {
                info('Composer finished provisioning the engine and its supplies.');
            } elseif ($dependencyState === 'failed') {
                warning('The project was forged, but Composer could not finish provisioning dependencies.');

                if ($installOutput !== null && $installOutput !== '') {
                    note($this->truncate($installOutput));
                }
            } else {
                note('The project is ready. When you are ready, run Composer once before you step into the editor or the runtime.');
            }

            outro("Next steps:\n- {$nextSteps[0]}\n- {$nextSteps[1]}\n- {$nextSteps[2]}" . (isset($nextSteps[3]) ? "\n- {$nextSteps[3]}" : ''));
            return;
        }

        $output->writeln(sprintf('<info>%s has been forged at %s.</info>', $displayName, $targetDirectory));

        if ($dependencyState === 'installed') {
            $output->writeln('<info>Composer dependencies were installed successfully.</info>');
        } elseif ($dependencyState === 'failed') {
            $output->writeln('<comment>Composer installation did not complete. The project scaffold still exists.</comment>');

            if ($installOutput !== null && $installOutput !== '') {
                $output->writeln($this->truncate($installOutput));
            }
        }

        $output->writeln('Next steps:');

        foreach ($nextSteps as $step) {
            $output->writeln(sprintf('  - %s', $step));
        }
    }

    private function normalizeBattleEngine(string $value): string
    {
        $normalized = strtolower(trim($value));

        return match ($normalized) {
            'traditional' => 'traditional',
            'active_time', 'active-time', 'atb' => 'active_time',
            default => throw new RuntimeException(sprintf('Unsupported battle engine "%s". Choose traditional or active_time.', $value)),
        };
    }

    private function findComposerBinary(): ?string
    {
        $binary = trim((string) shell_exec('command -v composer 2>/dev/null'));

        return $binary !== '' ? $binary : null;
    }

    private function slugify(string $value): string
    {
        $normalized = preg_replace('/[^A-Za-z0-9]+/', '-', trim($value)) ?? '';
        $normalized = trim($normalized, '-');

        return strtolower($normalized);
    }

    private function titleize(string $value): string
    {
        $words = preg_split('/[^A-Za-z0-9]+/', trim($value)) ?: [];
        $words = array_values(array_filter($words, static fn(string $word): bool => $word !== ''));

        if ($words === []) {
            return 'New Quest';
        }

        return implode(' ', array_map(static function (string $word): string {
            return match (strtolower($word)) {
                'rpg' => 'RPG',
                'jrpg' => 'JRPG',
                'cli' => 'CLI',
                'api' => 'API',
                'tui' => 'TUI',
                default => ucfirst(strtolower($word)),
            };
        }, $words));
    }

    private function toIdentifier(string $value): string
    {
        return strtopascal($this->slugify($value));
    }

    private function normalizePath(string $path): string
    {
        $path = trim($path);

        if ($path === '') {
            return $path;
        }

        if ($path[0] === '~') {
            $home = getenv('HOME') ?: '';
            $path = $home . substr($path, 1);
        }

        if (! $this->isAbsolutePath($path)) {
            $path = (getcwd() ?: '.') . DIRECTORY_SEPARATOR . $path;
        }

        $separator = DIRECTORY_SEPARATOR;
        $path = str_replace(['/', '\\'], $separator, $path);
        $prefix = '';

        if (preg_match('/^[A-Za-z]:\\\\/', $path) === 1) {
            $prefix = substr($path, 0, 2);
            $path = substr($path, 2);
        } elseif (str_starts_with($path, $separator . $separator)) {
            $prefix = $separator . $separator;
            $path = ltrim($path, $separator);
        } elseif (str_starts_with($path, $separator)) {
            $prefix = $separator;
            $path = ltrim($path, $separator);
        }

        $segments = preg_split('#[\\\\/]#', $path) ?: [];
        $normalized = [];

        foreach ($segments as $segment) {
            if ($segment === '' || $segment === '.') {
                continue;
            }

            if ($segment === '..') {
                array_pop($normalized);
                continue;
            }

            $normalized[] = $segment;
        }

        $joined = implode($separator, $normalized);

        if ($prefix !== '') {
            return rtrim($prefix, $separator) . $separator . $joined;
        }

        return $joined;
    }

    private function isAbsolutePath(string $path): bool
    {
        return str_starts_with($path, DIRECTORY_SEPARATOR)
            || preg_match('/^[A-Za-z]:[\\\\\\/]/', $path) === 1;
    }

    private function shellPath(string $path): string
    {
        return escapeshellarg($path);
    }

    private function truncate(string $value, int $limit = 800): string
    {
        if (strlen($value) <= $limit) {
            return $value;
        }

        return substr($value, 0, $limit - 3) . '...';
    }

    private function runTask(bool $isInteractive, callable $callback, string $message): mixed
    {
        if ($isInteractive) {
            return spin($callback, $message);
        }

        return $callback();
    }
}
