<?php declare(strict_types=1);

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
	 * @param array<string,mixed> $event
	 */
	public function push(array $event): void;

	/**
	 * Finalizes the stream and sends a last payload.
	 * 
	 * @param array<string,mixed> $finalPayload
	 */
	public function finish(array $finalPayload): void;
}
