<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;

/** Bounded newline-framed messages on the private loopback lifetime connection. */
final class TerminalSessionChannel
{
    private string $buffer = '';
    public const int MAX_MESSAGE_BYTES = 1048576;

    /** @param resource $stream */
    public function __construct(private $stream)
    {
        stream_set_blocking($stream, false);
    }

    /** @param array<string, mixed> $message */
    public function sendMessage(array $message, float $timeout = 1.0): void
    {
        $encoded = json_encode($message, JSON_THROW_ON_ERROR) . "\n";
        if (strlen($encoded) > self::MAX_MESSAGE_BYTES) {
            throw new RuntimeException('Terminal session message exceeds the resource limit.');
        }
        $deadline = microtime(true) + $timeout;
        while ($encoded !== '') {
            $written = @fwrite($this->stream, $encoded);
            if ($written === false || $this->isClosed() || microtime(true) >= $deadline) {
                throw new RuntimeException('Terminal session lifetime connection closed while writing.');
            }
            $encoded = substr($encoded, $written);
            if ($written === 0) {
                usleep(10000);
            }
        }
    }

    /** @return array<string, mixed>|null */
    public function receiveMessage(): ?array
    {
        $chunk = @fread($this->stream, 8192);
        if ($chunk !== false) {
            $this->buffer .= $chunk;
        }
        if (strlen($this->buffer) > self::MAX_MESSAGE_BYTES) {
            throw new RuntimeException('Terminal session message exceeds the resource limit.');
        }
        $newline = strpos($this->buffer, "\n");
        if ($newline === false) {
            return null;
        }
        $line = substr($this->buffer, 0, $newline);
        $this->buffer = substr($this->buffer, $newline + 1);
        $message = json_decode($line, true, flags: JSON_THROW_ON_ERROR);
        if (! is_array($message)) {
            throw new RuntimeException('Invalid terminal session message.');
        }

        return $message;
    }

    public function isClosed(): bool
    {
        return ! is_resource($this->stream) || feof($this->stream);
    }

    public function close(): void
    {
        if (is_resource($this->stream)) {
            fclose($this->stream);
        }
    }
}
