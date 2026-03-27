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
 * Factory for creating event streams for a given service and stream ID.
 */
interface IEventStreamFactory {

	/**
	 * Creates a new stream instance for a given service and stream ID.
	 * 
	 * @param string $serviceName Logical service identifier (e.g. "chatbot", "widget_xyz").
	 * @param string $streamId Unique stream id within the service (e.g. UUID).
	 */
	public function createStream(string $serviceName, string $streamId): IEventStream;
}
