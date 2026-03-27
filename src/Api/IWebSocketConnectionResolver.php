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
