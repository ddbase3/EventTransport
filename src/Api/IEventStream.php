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
