<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * WebSocket event stream – requires external WS server.
 * This implementation matches the IEventStream interface and mirrors SSE semantics.
 */
class WebSocketEventStream implements IEventStream
{
    /**
     * The low-level WS connection object.
     * Expected to have ->send(string) and ->isConnected() methods.
     */
    private $connection;

    private bool $started = false;
    private bool $finished = false;

    public function __construct()
    {
        // Created via DI; connection is injected later via init()
    }

    /**
     * Injects the low-level WebSocket connection object.
     * For example a Ratchet\ConnectionInterface or Swoole\WebSocket\Server connection.
     */
    public function init($connection): void
    {
        $this->connection = $connection;
    }

    /**
     * For WS, "start" does nothing: the connection is already open.
     */
    public function start(): void
    {
        $this->started = true;
    }

    /**
     * Sends a structured WS message representing an event.
     */
    public function push(string $event, array $data): void
    {
        if ($this->finished) {
            return;
        }
        if (!$this->started) {
            $this->start();
        }
        if ($this->isDisconnected()) {
            return;
        }

        $payload = [
            'type' => $event,
            'data' => $data,
        ];

        $this->sendJson($payload);
    }

    /**
     * Sends a "comment". WebSockets do not support comments like SSE.
     * We emulate this using a structured "meta" message or a ping frame.
     */
    public function sendComment(string $text): void
    {
        if ($this->finished) {
            return;
        }
        if (!$this->started) {
            $this->start();
        }
        if ($this->isDisconnected()) {
            return;
        }

        // Fallback: structured meta-message
        $payload = [
            'type' => 'comment',
            'data' => ['text' => $text]
        ];

        $this->sendJson($payload);
    }

    /**
     * Detects if connection is alive.
     * Different WS libraries use different methods:
     * - Ratchet: $conn->isConnected()
     * - Swoole: $server->isEstablished($fd)
     * - Workerman: $conn->getStatus()
     *
     * This checks for the common patterns.
     */
    public function isDisconnected(): bool
    {
        if (!$this->connection) {
            return true;
        }

        // Ratchet style
        if (method_exists($this->connection, 'isConnected')) {
            return !$this->connection->isConnected();
        }

        // Workerman style: check STATUS_CLOSED = 4
        if (method_exists($this->connection, 'getStatus')) {
            return $this->connection->getStatus() === 4;
        }

        // Swoole userland: user must override init() to adapt
        // If no method exists, we assume active.
        return false;
    }

    /**
     * Sends the final payload and closes the stream.
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
        if (!$this->isDisconnected()) {
            $this->sendJson([
                'type' => 'done',
                'data' => $finalPayload
            ]);
        }

        // Many WS frameworks close connection automatically after scripts end.
        // Some require explicit close(), but we do *not* call it here
        // because the server should control lifecycle.
    }

    /**
     * Sends JSON via WS connection — safe wrapper.
     */
    private function sendJson(array $payload): void
    {
        if (!$this->connection || $this->isDisconnected()) {
            return;
        }

        try {
            $json = json_encode($payload, JSON_UNESCAPED_UNICODE);
            $this->connection->send($json);
        } catch (\Throwable $e) {
            // swallow exceptions; the WS server will handle the disconnect
        }
    }
}

