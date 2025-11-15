<?php declare(strict_types=1);

namespace EventTransport\Api;

/**
 * Resolves a WebSocket connection for a given service and stream id.
 * Implementations may return null if no WS is available.
 */
interface IWebSocketConnectionResolver {

	/**
	 * @return mixed|null WebSocket connection object or null if not available
	 */
	public function resolve(string $serviceName, string $streamId);
}
