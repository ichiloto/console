<?php

declare(strict_types=1);

namespace Ichiloto\Console\Commands;

use Ichiloto\Console\Support\FigletForge;
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
use function Laravel\Prompts\table;
use function Laravel\Prompts\text;

#[AsCommand(
    name: 'generate:figlet',
    description: 'Forge FIGlet title art for title screens, menus, and terminal flourishes.',
)]
final class GenerateFigletCommand extends Command
{
    private const int DEFAULT_MAX_WIDTH = 72;

    public function configure(): void
    {
        $this
            ->addArgument('text', InputArgument::OPTIONAL, 'The words to render as FIGlet art.')
            ->addOption('font', 'f', InputOption::VALUE_REQUIRED, 'Render using an exact FIGlet font.')
            ->addOption('style', null, InputOption::VALUE_REQUIRED, 'Render using a curated Ichiloto FIGlet style.')
            ->addOption('output', 'o', InputOption::VALUE_REQUIRED, 'Write the FIGlet art to a file instead of stdout.')
            ->addOption('list-fonts', null, InputOption::VALUE_NONE, 'List the available styles and installed FIGlet fonts.')
            ->addOption('max-width', null, InputOption::VALUE_REQUIRED, 'Preferred maximum width for curated styles.', (string) self::DEFAULT_MAX_WIDTH);
    }

    public function execute(InputInterface $input, OutputInterface $output): int
    {
        $isInteractive = $input->isInteractive();
        $figletForge = new FigletForge();

        try {
            if ((bool) $input->getOption('list-fonts')) {
                $this->renderCatalog($figletForge, $output, $isInteractive);
                return Command::SUCCESS;
            }

            if (trim((string) ($input->getOption('font') ?? '')) !== '' && trim((string) ($input->getOption('style') ?? '')) !== '') {
                throw new RuntimeException('Choose either --font or --style, not both.');
            }

            $figletForge->assertPackageInstalled();

            if ($isInteractive) {
                intro('The court engravers await. Let us carve a banner worthy of your next quest.');
            }

            $text = $this->resolveText($input, $isInteractive);
            $selection = $this->resolveSelection($input, $isInteractive, $figletForge);
            $maxWidth = $this->resolveMaxWidth($input);
            $rendered = $figletForge->forge($text, $selection, $maxWidth);
            $outputPath = $this->resolveOutputPath($input, $isInteractive, $text);

            if ($isInteractive) {
                note("Preview:\n" . $rendered['art']);
            }

            if ($outputPath !== null) {
                $this->writeOutput($outputPath, $rendered['art']);

                if ($isInteractive) {
                    info(sprintf('Banner inscribed at %s using `%s`.', $outputPath, $rendered['font']));
                    outro('You can point that file at `assets/Graphics/System/title.txt` or drop it anywhere else the project needs terminal art.');
                } else {
                    $output->writeln(sprintf('<info>FIGlet art written to %s using "%s".</info>', $outputPath, $rendered['font']));
                }

                return Command::SUCCESS;
            }

            if ($isInteractive) {
                info(sprintf('Rendered with `%s`.', $rendered['font']));
                outro('Use `--output` next time if you want to write the banner straight into your project files.');
                return Command::SUCCESS;
            }

            $output->write($rendered['art']);

            return Command::SUCCESS;
        } catch (Throwable $throwable) {
            if ($isInteractive) {
                error($throwable->getMessage());
            } else {
                $output->writeln(sprintf('<error>%s</error>', $throwable->getMessage()));
            }

            return Command::FAILURE;
        }
    }

    private function resolveText(InputInterface $input, bool $isInteractive): string
    {
        $value = trim((string) ($input->getArgument('text') ?? ''));

        if ($value !== '') {
            return $value;
        }

        if (! $isInteractive) {
            throw new RuntimeException('Please provide the text to render, for example: ichiloto generate:figlet "Moonfall Legend"');
        }

        return trim(text(
            label: 'What words should the engravers carve?',
            placeholder: 'Moonfall Legend',
            required: true,
            validate: static fn(string $text): ?string => trim($text) === '' ? 'Enter the words you want to render.' : null,
            hint: 'This can be a title screen banner, a menu plaque, or any other terminal flourish.',
        ));
    }

    private function resolveSelection(InputInterface $input, bool $isInteractive, FigletForge $figletForge): string
    {
        $font = trim((string) ($input->getOption('font') ?? ''));

        if ($font !== '') {
            return $font;
        }

        $style = trim((string) ($input->getOption('style') ?? ''));

        if ($style !== '') {
            return $style;
        }

        if (! $isInteractive) {
            return FigletForge::DEFAULT_STYLE;
        }

        $selection = select(
            label: 'How should the banner be carved?',
            options: array_merge(
                $figletForge->getStyleOptions(),
                ['__font__' => 'Choose an exact FIGlet font']
            ),
            default: FigletForge::DEFAULT_STYLE,
            hint: 'Title presets prefer signature fonts first and only fall back when the current FIGlet package does not ship the exact face yet.',
            info: 'Choose an exact font if you already know the precise FIGlet face you want.',
        );

        if ($selection !== '__font__') {
            return $selection;
        }

        return select(
            label: 'Choose the exact FIGlet font',
            options: $figletForge->getFontOptions(),
            default: 'big',
            hint: 'This skips the style fallback and renders with the exact font you choose.',
        );
    }

    private function resolveMaxWidth(InputInterface $input): int
    {
        $value = trim((string) ($input->getOption('max-width') ?? self::DEFAULT_MAX_WIDTH));

        if (! ctype_digit($value) || (int) $value < 1) {
            throw new RuntimeException('--max-width must be a positive integer.');
        }

        return (int) $value;
    }

    private function resolveOutputPath(InputInterface $input, bool $isInteractive, string $text): ?string
    {
        $provided = trim((string) ($input->getOption('output') ?? ''));

        if ($provided !== '') {
            return $this->normalizePath($provided);
        }

        if (! $isInteractive) {
            return null;
        }

        $defaultOutput = $this->defaultOutputPath($text);
        $shouldWrite = confirm(
            label: 'Write this banner to a file?',
            default: $defaultOutput !== null,
            yes: 'Write it',
            no: 'Preview only',
            hint: $defaultOutput !== null
                ? 'Because you are inside a project, the default target is assets/Graphics/System/title.txt.'
                : 'You can always rerun with --output when you want to save it.',
        );

        if (! $shouldWrite) {
            return null;
        }

        $path = $this->normalizePath(text(
            label: 'Where should the banner be written?',
            default: $defaultOutput ?? $this->normalizePath((getcwd() ?: '.') . DIRECTORY_SEPARATOR . 'title.txt'),
            required: true,
            validate: static fn(string $value): ?string => trim($value) === '' ? 'Choose a destination file.' : null,
            hint: 'Existing files can be replaced if you confirm the overwrite.',
        ));

        if (is_file($path) && ! confirm(
            label: sprintf('%s already exists. Overwrite it?', $path),
            default: false,
            yes: 'Overwrite',
            no: 'Cancel',
        )) {
            throw new RuntimeException('FIGlet generation cancelled before overwriting the existing file.');
        }

        return $path;
    }

    private function defaultOutputPath(string $text): ?string
    {
        $workingDirectory = getcwd() ?: '.';

        if (is_valid_working_dir($workingDirectory)) {
            return $this->normalizePath($workingDirectory . DIRECTORY_SEPARATOR . 'assets/Graphics/System/title.txt');
        }

        $slug = trim((string) preg_replace('/[^A-Za-z0-9]+/', '-', strtolower($text)), '-');

        if ($slug === '') {
            $slug = 'title-banner';
        }

        return $this->normalizePath($workingDirectory . DIRECTORY_SEPARATOR . $slug . '.txt');
    }

    private function writeOutput(string $path, string $contents): void
    {
        $directory = dirname($path);

        if (! is_dir($directory) && ! mkdir($directory, 0777, true) && ! is_dir($directory)) {
            throw new RuntimeException(sprintf('Unable to create %s.', $directory));
        }

        $bytes = file_put_contents($path, $contents);

        if ($bytes === false) {
            throw new RuntimeException(sprintf('Unable to write %s.', $path));
        }
    }

    private function renderCatalog(FigletForge $figletForge, OutputInterface $output, bool $isInteractive): void
    {
        $figletForge->assertPackageInstalled();

        $styleRows = [];

        foreach ($figletForge->getStyleOptions() as $style => $label) {
            $styleRows[] = [$style, $label];
        }

        $fontRows = array_map(static fn(string $font): array => [$font], $figletForge->getAvailableFonts());

        if ($isInteractive) {
            intro('The guild archives contain both curated house styles and exact FIGlet fonts.');
            table(['Style', 'Meaning'], $styleRows);
            table(['FIGlet Font'], $fontRows);
            outro('Use `ichiloto generate:figlet "Moonfall Legend" --font slant` for exact control, or `--style epic` for Ichiloto-curated defaults.');
            return;
        }

        $output->writeln('Available styles:');

        foreach ($styleRows as [$style, $label]) {
            $output->writeln(sprintf('  - %s: %s', $style, $label));
        }

        $output->writeln('');
        $output->writeln('Available FIGlet fonts:');

        foreach ($fontRows as [$font]) {
            $output->writeln(sprintf('  - %s', $font));
        }
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
}
