<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use Closure;

final class TerminalInteractivity
{
    /** @var Closure(): bool */
    private readonly Closure $inputIsTty;

    /** @var Closure(): bool */
    private readonly Closure $outputIsTty;

    /**
     * @param callable(): bool|null $inputIsTty
     * @param callable(): bool|null $outputIsTty
     */
    public function __construct(?callable $inputIsTty = null, ?callable $outputIsTty = null)
    {
        $this->inputIsTty = $inputIsTty === null
            ? static fn (): bool => function_exists('stream_isatty') && @stream_isatty(STDIN)
            : Closure::fromCallable($inputIsTty);
        $this->outputIsTty = $outputIsTty === null
            ? static fn (): bool => function_exists('stream_isatty') && @stream_isatty(STDOUT)
            : Closure::fromCallable($outputIsTty);
    }

    public function supportsPrompts(): bool
    {
        return ($this->inputIsTty)() && ($this->outputIsTty)();
    }
}
