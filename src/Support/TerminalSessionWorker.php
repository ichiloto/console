<?php

declare(strict_types=1);

namespace Ichiloto\Console\Support;

use RuntimeException;
use Throwable;

require_once __DIR__ . '/TerminalSessionChannel.php';
require_once __DIR__ . '/TerminalSessionProcess.php';
require_once __DIR__ . '/TerminalSessionScratch.php';
require_once __DIR__ . '/TerminalSessionWindow.php';

/** Runs inside the new terminal, not in the GUI's process tree. */
final class TerminalSessionWorker
{
    /** @param list<string> $arguments */
    public static function run(array $arguments, ?TerminalSessionWindow $window = null): int
    {
        $window ??= new TerminalSessionWindow();
        [$address, $nonce, $expires] = array_pad($arguments, 3, '');
        if (! preg_match('/^127\.0\.0\.1:\d+$/D', $address) || ! preg_match('/^[a-f0-9]{64}$/D', $nonce)
            || ! is_numeric($expires) || (float) $expires <= microtime(true)) {
            if (preg_match('/^[a-f0-9]{64}$/D', $nonce)) {
                $window->closeOwnedWindow($nonce, wait: false);
            }
            return 125;
        }
        $channel = null;
        $game = null;
        $scratch = null;
        $code = 125;
        $cancelled = false;
        $cancelSignal = 0;
        $handlers = [];
        $asyncSignals = null;
        $input = STDIN;
        $output = STDOUT;
        $errors = STDERR;
        $diagnosticErrors = null;
        try {
            // PHP's CREATE_NEW_CONSOLE still supplies the parent's standard handles.
            // Bind explicitly to this console, never the GUI's inherited log handles.
            if (PHP_OS_FAMILY === 'Windows') {
                $input = @fopen('CONIN$', 'rb');
                $output = @fopen('CONOUT$', 'wb');
                $errors = @fopen('CONOUT$', 'wb');
                if (! is_resource($input) || ! is_resource($output) || ! is_resource($errors)) {
                    throw new RuntimeException('The owned Windows console did not supply real terminal streams.');
                }
            }
            $stream = @stream_socket_client('tcp://' . $address, $error, $message, min(1.0, (float) $expires - microtime(true)));
            if (! is_resource($stream)) {
                throw new RuntimeException('The owning Play process is no longer available.');
            }
            $channel = new TerminalSessionChannel($stream);
            $channel->sendMessage(['type' => 'hello', 'nonce' => $nonce]);
            $launch = null;
            while ($launch === null && ! $channel->isClosed() && microtime(true) < (float) $expires) {
                $launch = $channel->receiveMessage();
                usleep(10000);
            }
            if (($launch['type'] ?? null) !== 'launch' || $channel->isClosed() || microtime(true) >= (float) $expires) {
                throw new RuntimeException('The owned Terminal launch expired before authorization.');
            }
            if (function_exists('pcntl_signal')) {
                $asyncSignals = pcntl_async_signals(true);
                foreach ([SIGINT, SIGTERM, SIGHUP] as $number) {
                    $handlers[$number] = pcntl_signal_get_handler($number);
                    pcntl_signal($number, static function (int $received) use (&$cancelled, &$cancelSignal): void {
                        $cancelled = true;
                        $cancelSignal = $received;
                    });
                }
            }
            $scratch = new TerminalSessionScratch($launch['scratchRoot'], $nonce);
            $scratch->create();
            // Never start a game after a disconnected/expired startup rendezvous.
            $channel->receiveMessage();
            if ($cancelled || $channel->isClosed() || microtime(true) >= (float) $expires) {
                throw new RuntimeException('The owning Play process cancelled before the game started.');
            }
            $executable = $launch['arguments'][0];
            if ((str_contains($executable, '/') || str_contains($executable, '\\')) && (! is_file($executable) || ! is_executable($executable))) {
                throw new RuntimeException('Terminal game executable is unavailable: ' . $executable);
            }
            $diagnosticPath = $launch['environment']['ICHILOTO_TERMINAL_DIAGNOSTIC_LOG'] ?? null;
            if ($diagnosticPath !== null) {
                $diagnosticErrors = @fopen($diagnosticPath, 'ab');
                if (!is_resource($diagnosticErrors)) {
                    throw new RuntimeException('Could not open the Terminal game diagnostic log.');
                }
                $errors = $diagnosticErrors;
            }
            $process = TerminalSessionProcess::start($launch['arguments'], [0 => $input, 1 => $output, 2 => $errors], $launch['directory'], $launch['environment']);
            if (! is_resource($process)) {
                throw new RuntimeException('Could not start the game in its owned Terminal.');
            }
            $game = new TerminalSessionProcess($process);
            $channel->sendMessage(['type' => 'ready']);
            $nextSnapshot = 0.0;
            while ($game->isRunning()) {
                $packet = $channel->receiveMessage();
                if ($cancelled || $channel->isClosed() || ($packet['type'] ?? null) === 'cancel') {
                    $cancelled = true;
                    $code = 143;
                    break;
                }
                if (microtime(true) >= $nextSnapshot) {
                    $game->rememberDescendants();
                    $nextSnapshot = microtime(true) + 0.2;
                }
                usleep(20000);
            }
            if (! $cancelled) {
                $code = $game->getExitCode() ?? 125;
            } else {
                $code = 128 + ($cancelSignal ?: 15);
            }
            // Reap residual owned consumers before telling the editor to remove its overlay.
            $game->stop();
            $game = null;
            $scratch->remove();
            $scratch = null;
            if (! $channel->isClosed()) {
                $channel->sendMessage(['type' => 'complete', 'exitCode' => $code]);
            }
        } catch (Throwable $exception) {
            fwrite(STDERR, 'Terminal session: ' . $exception->getMessage() . "\n");
            if ($channel !== null && ! $channel->isClosed()) {
                try {
                    $channel->sendMessage(['type' => 'failed', 'reason' => $exception->getMessage()]);
                } catch (Throwable) {
                }
            }
        } finally {
            $game?->stop();
            $scratch?->remove();
            $channel?->close();
            if (is_resource($diagnosticErrors)) {
                fclose($diagnosticErrors);
            }
        }

        // No crash prompt in editor-owned mode. Logs, not a surviving window,
        // preserve the diagnosis; this also covers abrupt owner death.
        $window->closeOwnedWindow($nonce, wait: false);
        foreach ($handlers as $number => $handler) {
            pcntl_signal($number, $handler);
        }
        if ($asyncSignals !== null) {
            pcntl_async_signals($asyncSignals);
        }

        return $code;
    }
}

if (realpath($_SERVER['SCRIPT_FILENAME'] ?? '') === __FILE__) {
    if (! isset($_SERVER['argv']) || ! is_array($_SERVER['argv'])) {
        fwrite(STDERR, "Terminal worker requires CLI argument support.\n");
        exit(125);
    }
    exit(TerminalSessionWorker::run(array_slice($_SERVER['argv'], 1)));
}
