<?php

declare(strict_types=1);

namespace Ichiloto\Console\Renderer;

use InvalidArgumentException;

final readonly class RendererDescriptor
{
    public string $id;

    public string $displayName;

    public function __construct(string $id, string $displayName)
    {
        $id = strtolower(trim($id));
        $displayName = trim($displayName);

        if ($id === '') {
            throw new InvalidArgumentException('A renderer ID cannot be empty.');
        }

        if ($displayName === '') {
            throw new InvalidArgumentException('A renderer display name cannot be empty.');
        }

        $this->id = $id;
        $this->displayName = $displayName;
    }
}
