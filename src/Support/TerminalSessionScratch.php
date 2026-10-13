<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;

/** Created only by an authenticated worker; no pre-start script can be orphaned. */
final readonly class TerminalSessionScratch
{
    public string $path;

    public function __construct(string $root, private string $nonce)
    {
        if (! preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
            throw new RuntimeException('Invalid terminal session ownership token.');
        }
        $this->path = rtrim($root, '/\\') . DIRECTORY_SEPARATOR . 'ichiloto-terminal-' . $nonce;
    }

    public function create(): void
    {
        if (! @mkdir($this->path, 0700)) {
            throw new RuntimeException('Cannot create private terminal session scratch.');
        }
        $marker = @fopen($this->path . '/owner', 'x');
        if (! is_resource($marker)) {
            @rmdir($this->path);
            throw new RuntimeException('Cannot record terminal session scratch ownership.');
        }
        try {
            @chmod($this->path . '/owner', 0600);
            $written = @fwrite($marker, $this->nonce);
        } finally {
            fclose($marker);
        }
        if ($written !== strlen($this->nonce)) {
            @unlink($this->path . '/owner');
            @rmdir($this->path);
            throw new RuntimeException('Cannot write terminal session scratch ownership.');
        }
    }

    public function remove(): void
    {
        // Never recurse into an unrelated directory or follow a substituted link.
        if (is_link($this->path) || is_link($this->path . '/owner')
            || ! is_file($this->path . '/owner')
            || @file_get_contents($this->path . '/owner') !== $this->nonce) {
            return;
        }
        $entries = @scandir($this->path);
        if ($entries !== ['.', '..', 'owner']) {
            return;
        }
        @unlink($this->path . '/owner');
        @rmdir($this->path);
    }
}
