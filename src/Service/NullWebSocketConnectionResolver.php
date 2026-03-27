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
