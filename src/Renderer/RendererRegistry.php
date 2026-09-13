<?php

declare(strict_types=1);

namespace Ichiloto\Console\Renderer;

use InvalidArgumentException;

final class RendererRegistry
{
    /** @var array<string, RendererDescriptor> */
    private array $renderers = [];

    /**
     * @param list<RendererDescriptor>|null $renderers
     */
    public function __construct(?array $renderers = null)
    {
        $renderers ??= [
            new RendererDescriptor('terminal', 'Native Terminal'),
            new RendererDescriptor('gpui', 'GPUI'),
        ];

        foreach ($renderers as $renderer) {
            if (isset($this->renderers[$renderer->id])) {
                throw new InvalidArgumentException(sprintf(
                    'Renderer ID "%s" is registered more than once.',
                    $renderer->id,
                ));
            }

            $this->renderers[$renderer->id] = $renderer;
        }
    }

    /**
     * @return list<RendererDescriptor>
     */
    public function all(): array
    {
        return array_values($this->renderers);
    }

    public function find(string $id): ?RendererDescriptor
    {
        return $this->renderers[$this->normalize($id)] ?? null;
    }

    public function require(string $id): RendererDescriptor
    {
        $renderer = $this->find($id);

        if ($renderer !== null) {
            return $renderer;
        }

        throw new InvalidArgumentException(sprintf(
            'Unknown renderer "%s". Valid renderer IDs: %s.',
            trim($id),
            implode(', ', $this->ids()),
        ));
    }

    /**
     * @return list<string>
     */
    public function ids(): array
    {
        return array_keys($this->renderers);
    }

    /**
     * @return array<string, string>
     */
    public function options(): array
    {
        $options = [];

        foreach ($this->renderers as $renderer) {
            $options[$renderer->id] = $renderer->displayName;
        }

        return $options;
    }

    private function normalize(string $id): string
    {
        return strtolower(trim($id));
    }
}
