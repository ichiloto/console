<?php

declare(strict_types=1);

namespace Ichiloto\Console\Renderer;

use Closure;
use InvalidArgumentException;
use UnexpectedValueException;

use function Laravel\Prompts\select;

final class RendererSelector
{
    /** @var Closure(string, array<string, string>): (int|string) */
    private readonly Closure $prompt;

    /**
     * @param callable(string, array<string, string>): (int|string)|null $prompt
     */
    public function __construct(
        private readonly RendererRegistry $registry,
        ?callable $prompt = null,
    ) {
        $this->prompt = $prompt === null
            ? static fn (string $label, array $options): int|string => select(
                label: $label,
                options: $options,
            )
            : Closure::fromCallable($prompt);
    }

    public function resolve(
        ?string $rendererOption,
        bool $gpuiAlias,
        bool $canPrompt,
    ): RendererDescriptor {
        if ($rendererOption !== null) {
            $renderer = $this->registry->require($rendererOption);

            if ($gpuiAlias && $renderer->id !== 'gpui') {
                throw new InvalidArgumentException(sprintf(
                    'The --gpui-renderer alias conflicts with --renderer=%s.',
                    $renderer->id,
                ));
            }

            return $renderer;
        }

        if ($gpuiAlias) {
            return $this->registry->require('gpui');
        }

        if (! $canPrompt) {
            return $this->registry->require('terminal');
        }

        $selected = ($this->prompt)('Select renderer', $this->registry->options());

        if (! is_string($selected)) {
            throw new UnexpectedValueException('Renderer selection did not return a renderer ID.');
        }

        return $this->registry->require($selected);
    }
}
