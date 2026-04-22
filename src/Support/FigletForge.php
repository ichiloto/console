<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Amasiye\Figlet\Figlet;
use ReflectionClass;
use RuntimeException;
use Throwable;

final class FigletForge
{
    public const string DEFAULT_STYLE = 'delta-corps-priest-1';
    private const int DEFAULT_MAX_WIDTH = 72;

    /**
     * @var array<string, array{label: string, fonts: string[]}>
     */
    private const array STYLES = [
        'delta-corps-priest-1' => [
            'label' => 'Delta Corps Priest 1 (Default)',
            'fonts' => ['Delta Corps Priest 1'],
        ],
        'ansi-shadow' => [
            'label' => 'ANSI Shadow',
            'fonts' => ['ANSI Shadow'],
        ],
        'bloody' => [
            'label' => 'Bloody',
            'fonts' => ['Bloody'],
        ],
        'dos-rebel' => [
            'label' => 'DOS Rebel',
            'fonts' => ['DOS Rebel'],
        ],
        'elite' => [
            'label' => 'Elite',
            'fonts' => ['Elite'],
        ],
        'epic' => [
            'label' => 'Epic Banner',
            'fonts' => ['block', 'big', 'standard', 'small'],
        ],
        'heroic' => [
            'label' => 'Heroic Crest',
            'fonts' => ['doom', 'standard', 'small'],
        ],
        'classic' => [
            'label' => 'Classic Adventure',
            'fonts' => ['standard', 'small'],
        ],
        'swift' => [
            'label' => 'Swift Slant',
            'fonts' => ['slant', 'standard', 'small'],
        ],
        'regal' => [
            'label' => 'Regal Chronicle',
            'fonts' => ['roman', 'standard', 'small'],
        ],
    ];

    /**
     * @var string[]|null
     */
    private ?array $availableFonts = null;

    public function assertPackageInstalled(): void
    {
        if (! class_exists(Figlet::class)) {
            throw new RuntimeException(
                'FIGlet support requires the amasiye/figlet package. Update that package, then run composer install before using title-art commands.'
            );
        }
    }

    /**
     * @return array<string, string>
     */
    public function getStyleOptions(): array
    {
        $options = [];

        foreach (self::STYLES as $style => $definition) {
            $label = $definition['label'];
            $primaryFont = $definition['fonts'][0];

            if (! str_contains(strtolower($label), strtolower($primaryFont))) {
                $label = sprintf('%s (%s)', $label, $primaryFont);
            }

            $options[$style] = $label;
        }

        return $options;
    }

    public function getStyleLabel(string $style): string
    {
        return self::STYLES[$style]['label'] ?? $style;
    }

    /**
     * @return array<string, string>
     */
    public function getFontOptions(): array
    {
        $options = [];

        foreach ($this->getAvailableFonts() as $font) {
            $options[$font] = $font;
        }

        return $options;
    }

    /**
     * @return string[]
     */
    public function getAvailableFonts(): array
    {
        $this->assertPackageInstalled();

        if ($this->availableFonts !== null) {
            return $this->availableFonts;
        }

        $fontDirectory = $this->getFontDirectory();
        $fontFiles = glob($fontDirectory . '*.flf') ?: [];
        $fonts = array_map(static fn(string $file): string => basename($file, '.flf'), $fontFiles);
        sort($fonts);

        return $this->availableFonts = $fonts;
    }

    /**
     * @return array{art: string, font: string, selection: string}
     */
    public function forge(string $text, ?string $selection = null, int $maxWidth = self::DEFAULT_MAX_WIDTH): array
    {
        $this->assertPackageInstalled();

        $selection = trim((string) $selection);
        $selection = $selection !== '' ? $selection : self::DEFAULT_STYLE;
        $maxWidth = max(1, $maxWidth);

        $candidates = $this->resolveFontCandidates($selection);
        $lastRenderedArt = null;
        $lastRenderedFont = null;

        foreach ($candidates as $candidate) {
            $art = $this->renderWithFont($text, $candidate);

            if ($art === null) {
                continue;
            }

            $lastRenderedArt = $art;
            $lastRenderedFont = $candidate;

            if ($this->measureWidth($art) <= $maxWidth) {
                break;
            }
        }

        if ($lastRenderedArt === null || $lastRenderedFont === null) {
            throw new RuntimeException(sprintf('Could not render FIGlet art using "%s".', $selection));
        }

        return [
            'art' => $lastRenderedArt,
            'font' => $lastRenderedFont,
            'selection' => $selection,
        ];
    }

    /**
     * @return string[]
     */
    private function resolveFontCandidates(string $selection): array
    {
        $normalizedSelection = $this->normalizeFontIdentifier($selection);

        foreach (self::STYLES as $styleKey => $definition) {
            $labelWithoutNotes = preg_replace('/\s*\(.*/', '', $definition['label']) ?? $definition['label'];

            if (
                $normalizedSelection === $this->normalizeFontIdentifier($styleKey)
                || $normalizedSelection === $this->normalizeFontIdentifier($labelWithoutNotes)
            ) {
                return $this->resolveInstalledCandidates($definition['fonts']);
            }
        }

        $fontName = $this->resolveFontName($selection);

        if ($fontName === null) {
            throw new RuntimeException(sprintf(
                'Unknown FIGlet font or style "%s". Choose one of: %s, or any installed FIGlet font.',
                $selection,
                implode(', ', array_keys(self::STYLES)),
            ));
        }

        return [$fontName];
    }

    /**
     * @param string[] $candidates
     * @return string[]
     */
    private function resolveInstalledCandidates(array $candidates): array
    {
        $resolved = [];

        foreach ($candidates as $candidate) {
            $fontName = $this->resolveFontName($candidate);

            if ($fontName === null || in_array($fontName, $resolved, true)) {
                continue;
            }

            $resolved[] = $fontName;
        }

        return $resolved;
    }

    private function resolveFontName(string $requestedFont): ?string
    {
        $fontsByNormalizedName = [];

        foreach ($this->getAvailableFonts() as $font) {
            $fontsByNormalizedName[$this->normalizeFontIdentifier($font)] = $font;
        }

        return $fontsByNormalizedName[$this->normalizeFontIdentifier($requestedFont)] ?? null;
    }

    private function normalizeFontIdentifier(string $value): string
    {
        $normalized = strtolower(trim($value));
        $normalized = preg_replace('/[^a-z0-9]+/', '', $normalized) ?? '';

        return $normalized;
    }

    private function getFontDirectory(): string
    {
        $reflection = new ReflectionClass(Figlet::class);

        return dirname((string) $reflection->getFileName()) . '/fonts/';
    }

    private function renderWithFont(string $text, string $font): ?string
    {
        try {
            $figlet = new Figlet();
            $figlet->setFont($font);

            return $this->normalizeRenderedOutput($figlet->render($text));
        } catch (Throwable) {
            return null;
        }
    }

    private function normalizeRenderedOutput(string $renderedOutput): string
    {
        $lines = preg_split('/\R/', rtrim($renderedOutput, "\r\n")) ?: [];
        $lines = array_map(static fn(string $line): string => rtrim($line), $lines);

        while ($lines !== [] && end($lines) === '') {
            array_pop($lines);
        }

        return implode(PHP_EOL, $lines) . PHP_EOL;
    }

    private function measureWidth(string $text): int
    {
        $lines = preg_split('/\R/', rtrim($text, "\r\n")) ?: [];
        $width = 0;

        foreach ($lines as $line) {
            $width = max($width, strlen(rtrim($line)));
        }

        return $width;
    }
}
