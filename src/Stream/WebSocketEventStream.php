<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * WebSocket event stream – requires external WS server.
 * This implementation is a placeholder to match the IEventStream interface.
 */
class WebSocketEventStream implements IEventStream {

	/**
	 * Placeholder for a WS connection object (Ratchet, Swoole etc.)
	 */
	private $connection;

	public function __construct() {
		// DI-only constructor
	}

	/**
	 * Must be injected by factory or resolver.
	 */
	public function init($connection): void {
		$this->connection = $connection;
	}

	public function start(): void {
		// WebSocket has persistent connection, nothing to send here.
	}

	public function push(array $event): void {
		if ($this->connection) {
			$this->connection->send(json_encode($event));
		}
	}

	public function finish(array $finalPayload): void {
		if ($this->connection) {
			$this->connection->send(json_encode([
				'type' => 'done',
				'data' => $finalPayload
			]));
		}
	}
}
