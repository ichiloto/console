<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

/** A source fingerprint and its installed state, obtained without building. */
final readonly class SourceRendererUpdate
{
    public function __construct(
        public string $renderer,
        public string $platform,
        public string $source,
        public string $builder,
        public string $boundary,
        public string $fingerprint,
        public bool $current,
        public bool $skipped,
    ) {
    }

    /** @return array<string, int|string> */
    public function getIdentity(): array
    {
        return [
            'version' => 1,
            'source' => $this->source,
            'builder' => $this->builder,
            'renderer' => $this->renderer,
            'platform' => $this->platform,
            'profile' => 'release',
            'fingerprint' => $this->fingerprint,
        ];
    }

    public function getReceiptFile(): string
    {
        return $this->boundary . '/installed/.source-' . $this->renderer . '-' . $this->platform . '.json';
    }

    public function getSkipFile(): string
    {
        return $this->boundary . '/installed/.source-skip-' . $this->renderer . '-' . $this->platform . '.json';
    }
}
