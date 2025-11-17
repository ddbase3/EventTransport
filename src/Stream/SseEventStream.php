<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Server-Sent Events stream (SSE).
 * Real streaming if the server supports it.
 */
class SseEventStream implements IEventStream
{
    private bool $started = false;
    private bool $finished = false;

    /**
     * Prepare and start the SSE stream.
     */
    public function start(): void
    {
        if ($this->started) return;
        $this->started = true;

        // Clean existing output buffers (avoid buffering interference)
        while (ob_get_level() > 0) {
            @ob_end_clean();
        }

        if (!headers_sent()) {
            header("Content-Type: text/event-stream");
            header("Cache-Control: no-cache");
            header("X-Accel-Buffering: no");
            header("Connection: keep-alive");
        }

        // Make PHP flush output immediately
        @ini_set("implicit_flush", "1");
        @ini_set("output_buffering", "off");
        ob_implicit_flush(true);

        // Initial empty line to begin SSE stream
        echo "\n";
        flush();
    }

    /**
     * Push an SSE event with JSON payload.
     */
    public function push(string $event, array $data): void
    {
        if ($this->finished) return;
        if (!$this->started) $this->start();
        if ($this->isDisconnected()) return;

        echo "event: {$event}\n";
        echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

        flush();
    }

    /**
     * Send an SSE comment (heartbeat).
     * Useful to keep connections alive.
     */
    public function sendComment(string $text): void
    {
        if ($this->finished) return;
        if (!$this->started) $this->start();
        if ($this->isDisconnected()) return;

        // SSE comment line
        echo ": {$text}\n\n";

        flush();
    }

    /**
     * Returns true if the client has disconnected.
     */
    public function isDisconnected(): bool
    {
        // PHP-native detection of aborted connection
        // (returns 1 if aborted, 0 otherwise)
        return connection_aborted() === 1;
    }

    /**
     * Send a final event and close the stream.
     */
    public function finish(array $finalPayload): void
    {
        if ($this->finished) return;
        $this->finished = true;

        if (!$this->started) {
            $this->start();
        }

        if (!$this->isDisconnected()) {
            echo "event: done\n";
            echo "data: " . json_encode($finalPayload, JSON_UNESCAPED_UNICODE) . "\n\n";
            flush();
        }

        // Optionally: End the script to ensure no extra output is added.
        // But leave it commented so the caller can decide.
        // exit;
    }
}
