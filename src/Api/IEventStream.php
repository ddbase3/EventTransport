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
 * Represents a single logical event stream (e.g. one chat, one widget update).
 * Each HTTP request may create one or more streams with different IDs.
 */
interface IEventStream {

	/**
	 * Called once when the stream starts.
	 */
	public function start(): void;

	/**
	 * Pushes an incremental event to the client.
	 * 
	 * @param string $event
	 * @param array<string,mixed> $data
	 */
	public function push(string $event, array $data): void;

	/**
	 * Sends an SSE comment (keep-alive / meta info).
	 */
	public function sendComment(string $text): void;

	/**
	 * Detects if the client has disconnected.
	 */
	public function isDisconnected(): bool;

	/**
	 * Finalizes the stream and sends a last payload.
	 * 
	 * @param array<string,mixed> $finalPayload
	 */
	public function finish(array $finalPayload): void;
}
