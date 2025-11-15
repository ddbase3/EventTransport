<?php declare(strict_types=1);

namespace EventTransport\Service;

use EventTransport\Api\IWebSocketConnectionResolver;

/**
 * Default resolver that always returns null.
 * This effectively disables WebSocket streaming.
 */
class NullWebSocketConnectionResolver implements IWebSocketConnectionResolver {

	public function __construct() {
		// DI-only constructor
	}

	public function resolve(string $serviceName, string $streamId) {
		// No WebSocket server available
		return null;
	}
}
