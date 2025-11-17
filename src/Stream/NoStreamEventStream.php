<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Simple non-streaming response – sends one JSON payload at the end.
 * All events are buffered and emitted once in finish().
 */
class NoStreamEventStream implements IEventStream
{
    /** @var array<int, array<string,mixed>> */
    private array $buffer = [];

    private bool $started = false;
    private bool $finished = false;

    public function start(): void
    {
        if ($this->started) {
            return;
        }
        $this->started = true;

        if (!headers_sent()) {
            header('Content-Type: application/json; charset=utf-8');
            header('Cache-Control: no-cache, no-store, must-revalidate');
        }
    }

    /**
     * Buffer all pushed data so we can deliver it in one final JSON.
     */
    public function push(string $event, array $data): void
    {
        if ($this->finished) {
            return;
        }
        if (!$this->started) {
            $this->start();
        }

        $this->buffer[] = [
            'type' => $event,
            'data' => $data
        ];
    }

    /**
     * No-op for non-streaming output (kept for interface compatibility).
     */
    public function sendComment(string $text): void
    {
        // Non-streaming mode has no comments or heartbeats.
    }

    /**
     * Non-streaming always reports connection open during request lifecycle.
     */
    public function isDisconnected(): bool
    {
        return false;
    }

    /**
     * Emit one final JSON payload containing all buffered events.
     */
    public function finish(array $finalPayload): void
    {
        if ($this->finished) {
            return;
        }
        $this->finished = true;

        if (!$this->started) {
            $this->start();
        }

        $payload = [
            'type'   => 'done',
            'events' => $this->buffer,
            'data'   => $finalPayload,
        ];

        echo json_encode($payload, JSON_UNESCAPED_UNICODE);
    }
}

