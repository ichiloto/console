<?php

declare(strict_types=1);

namespace Ichiloto\Console\Launch;

use InvalidArgumentException;
use Symfony\Component\Console\Command\Command;
use Symfony\Component\Console\Input\InputInterface;
use Symfony\Component\Console\Input\InputOption;

/**
 * What a launching command (`play`, `battle`, `edit`) was told to open:
 * `--renderer=<id>`, or `--gui` (and its `--gpui-renderer` alias) for the
 * graphical option. Every launching command defines these options here, so
 * they read and mean the same everywhere.
 */
final class LaunchRequest
{
    public const string TERMINAL_ID = 'terminal';
    public const string GRAPHICAL_ID = 'gpui';
    public const string AUTO = 'auto';

    /** The flags that ask for the graphical option, in the order they are checked. */
    private const array GRAPHICAL_FLAGS = ['gui' => '--gui', 'gpui-renderer' => '--gpui-renderer'];

    /** Whether any choice flag was given; without one an interactive terminal is asked. */
    public bool $hasChoice {
        get => $this->targetId !== null || $this->graphicalFlags !== [];
    }

    /** The `--renderer` value, trimmed and lower case. */
    public readonly ?string $targetId;

    /**
     * @param string|null $targetId The `--renderer` value.
     * @param list<string> $graphicalFlags The given flags that ask for the graphical option.
     */
    public function __construct(
        ?string $targetId = null,
        public readonly array $graphicalFlags = [],
    ) {
        $this->targetId = $targetId === null ? null : strtolower(trim($targetId));
    }

    /**
     * Adds `--renderer`, `--gui` and `--gpui-renderer` to a launching command,
     * with help that says what an interactive run without them does.
     *
     * @param string $purpose What `--renderer` picks, such as "Renderer to use".
     * @param string $choices What the interactive list holds, such as "renderers".
     */
    public static function addOptions(Command $command, LaunchTargetSource $targets, string $purpose, string $choices): void
    {
        $names = $targets->getTargetNames();
        $graphical = $names[self::GRAPHICAL_ID] ?? self::GRAPHICAL_ID;

        $command
            ->addOption('renderer', null, InputOption::VALUE_REQUIRED, sprintf(
                '%s (%s; %s prefers %s when it is available)',
                $purpose,
                implode(', ', [...array_keys($names), self::AUTO]),
                self::AUTO,
                $graphical,
            ))
            ->addOption('gui', null, InputOption::VALUE_NONE, sprintf(
                'Open the graphical choice (%s) directly; the same as --renderer=%s',
                $graphical,
                self::GRAPHICAL_ID,
            ))
            ->addOption('gpui-renderer', null, InputOption::VALUE_NONE, 'The same as --gui, kept for existing scripts');

        $command->setHelp(trim($command->getHelp() . "\n\n" . sprintf(
            'Without --gui or --renderer, an interactive terminal lists the %s to choose from and shows why any of '
            . 'them is unavailable. A run with --no-interaction, or without a terminal, opens the %s. '
            . '--gui with --renderer=%s is refused.',
            $choices,
            $names[self::TERMINAL_ID] ?? self::TERMINAL_ID,
            self::TERMINAL_ID,
        )));
    }

    /** Reads the options `addOptions()` defined. */
    public static function fromInput(InputInterface $input): self
    {
        $renderer = $input->getOption('renderer');

        if ($renderer !== null && ! is_string($renderer)) {
            throw new InvalidArgumentException('The renderer option must be a renderer ID.');
        }

        $graphicalFlags = [];

        foreach (self::GRAPHICAL_FLAGS as $option => $flag) {
            if ((bool) $input->getOption($option)) {
                $graphicalFlags[] = $flag;
            }
        }

        return new self($renderer, $graphicalFlags);
    }

    /** Says why a graphical flag cannot stand beside a `--renderer` that chose `$chosenId`. */
    public function describeConflict(string $chosenId): string
    {
        $flag = $this->graphicalFlags[0] ?? '--gui';
        $subject = $flag === '--gpui-renderer' ? 'The --gpui-renderer alias' : 'The ' . $flag . ' option';

        return $this->targetId === self::AUTO
            ? sprintf('%s conflicts with --renderer=%s selecting %s.', $subject, self::AUTO, $chosenId)
            : sprintf('%s conflicts with --renderer=%s.', $subject, $chosenId);
    }
}
