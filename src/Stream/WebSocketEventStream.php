<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of EventTransport for BASE3 Framework.
 *
 * EventTransport extends the BASE3 framework with a unified event
 * transport layer for streaming and polling between PHP backends
 * and JavaScript frontends.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/eventtransport
 * https://github.com/ddbase3/EventTransport
 **********************************************************************/

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * WebSocket event stream - requires an external WS server.
 * This implementation matches the IEventStream interface and mirrors SSE semantics.
 */
class WebSocketEventStream implements IEventStream {
	/**
	 * The low-level WS connection object.
	 * Expected to provide ->send(string) and ->isConnected() methods.
	 */
	private $connection;

	private bool $started = false;
	private bool $finished = false;

	public function __construct() {
		// Created via DI; connection is injected later via init().
	}

	/**
	 * Injects the low-level WebSocket connection object.
	 * For example, a Ratchet\ConnectionInterface or Swoole\WebSocket\Server connection.
	 */
	public function init($connection): void {
		$this->connection = $connection;
	}

	/**
	 * For WS, start() does nothing because the connection is already open.
	 */
	public function start(): void {
		$this->started = true;
	}

	/**
	 * Sends a structured WS message representing an event.
	 */
	public function push(string $event, array $data): void {
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
	 * This is emulated using a structured meta message or a ping-like payload.
	 */
	public function sendComment(string $text): void {
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
			'type' => 'comment',
			'data' => ['text' => $text],
		];

		$this->sendJson($payload);
	}

	/**
	 * Detects whether the connection is still alive.
	 * Different WS libraries expose different methods:
	 * - Ratchet: $conn->isConnected()
	 * - Swoole: $server->isEstablished($fd)
	 * - Workerman: $conn->getStatus()
	 *
	 * This checks for the most common patterns.
	 */
	public function isDisconnected(): bool {
		if (!$this->connection) {
			return true;
		}

		// Ratchet-style connection check.
		if (method_exists($this->connection, 'isConnected')) {
			return !$this->connection->isConnected();
		}

		// Workerman-style check: STATUS_CLOSED = 4.
		if (method_exists($this->connection, 'getStatus')) {
			return $this->connection->getStatus() === 4;
		}

		// For other implementations, assume the connection is active.
		return false;
	}

	/**
	 * Sends the final payload and marks the stream as finished.
	 */
	public function finish(array $finalPayload): void {
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
				'data' => $finalPayload,
			]);
		}

		// Many WS frameworks close the connection automatically after the script ends.
		// Some require an explicit close(), but this implementation does not call it
		// because the server should control the connection lifecycle.
	}

	/**
	 * Sends JSON through the WS connection using a safe wrapper.
	 */
	private function sendJson(array $payload): void {
		if (!$this->connection || $this->isDisconnected()) {
			return;
		}

		try {
			$json = json_encode($payload, JSON_UNESCAPED_UNICODE);
			$this->connection->send($json);
		} catch (\Throwable $e) {
			// Swallow exceptions; the WS server is expected to handle disconnects.
		}
	}
}
