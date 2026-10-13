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

    /** @param array{schema: string, renderers: list<array{id: string, displayName: string, available: bool, reason: ?string}>, preferredId: ?string}|null $availabilityReport */
    public function resolve(
        ?string $rendererOption,
        bool $gpuiAlias,
        bool $canPrompt,
        ?array $availabilityReport = null,
    ): RendererDescriptor {
        if ($rendererOption !== null && strtolower(trim($rendererOption)) === 'auto') {
            if ($availabilityReport === null) {
                throw new InvalidArgumentException('--renderer=auto requires a project renderer availability report.');
            }
            $report = ProjectRendererAvailability::validateReport($availabilityReport);
            if ($report['preferredId'] === null) {
                throw new UnexpectedValueException('No renderer is available for this project.');
            }
            $renderer = $this->registry->require($report['preferredId']);
            if ($gpuiAlias && $renderer->id !== 'gpui') {
                throw new InvalidArgumentException('The --gpui-renderer alias conflicts with --renderer=auto selecting ' . $renderer->id . '.');
            }
            return $renderer;
        }

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
