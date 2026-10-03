<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

/**
 * Finds the native GUI editor executable. The console never builds or
 * downloads it: it uses `ICHILOTO_GUI_EDITOR` when set, otherwise a build in
 * the `gui-editor` checkout beside the console, release before debug.
 */
final readonly class GuiEditorLocator
{
    public const string ENVIRONMENT = 'ICHILOTO_GUI_EDITOR';
    public const string BINARY = 'ichiloto-gui-editor';

    public function __construct(private string $consoleRoot, private ?string $configured = null)
    {
    }

    public static function fromEnvironment(string $consoleRoot): self
    {
        $configured = getenv(self::ENVIRONMENT);

        return new self($consoleRoot, is_string($configured) && $configured !== '' ? $configured : null);
    }

    /**
     * The executable to run, or null with the reason in `describeMissing()`.
     */
    public function locate(): ?string
    {
        if ($this->configured !== null) {
            return is_file($this->configured) && is_executable($this->configured) ? $this->configured : null;
        }

        foreach ($this->getCandidates() as $candidate) {
            if (is_file($candidate) && is_executable($candidate)) {
                return $candidate;
            }
        }

        return null;
    }

    /** Why no executable was found, and how to get one. */
    public function describeMissing(): string
    {
        if ($this->configured !== null) {
            return sprintf('%s names %s, which is not an executable file.', self::ENVIRONMENT, $this->configured);
        }

        return sprintf(
            "The GUI editor is not built. Build it with `cargo build --release` in %s, or set %s to its executable.\nLooked for: %s",
            dirname($this->getCandidates()[0], 3),
            self::ENVIRONMENT,
            implode(', ', $this->getCandidates()),
        );
    }

    /** @return list<string> */
    private function getCandidates(): array
    {
        $checkout = dirname(rtrim($this->consoleRoot, DIRECTORY_SEPARATOR)) . DIRECTORY_SEPARATOR . 'gui-editor';

        return [
            $checkout . '/target/release/' . self::BINARY,
            $checkout . '/target/debug/' . self::BINARY,
        ];
    }
}
