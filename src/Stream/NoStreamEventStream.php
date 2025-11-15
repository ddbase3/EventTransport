<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Simple non-streaming response – sends one JSON payload at the end.
 */
class NoStreamEventStream implements IEventStream {

	/** @var array<string,mixed> */
	private array $buffer = [];

	public function start(): void {
		// Make sure correct header is sent once.
		if (!headers_sent()) {
			header('Content-Type: application/json; charset=utf-8');
			header('Cache-Control: no-cache, no-store, must-revalidate');
		}
	}

	public function push(array $event): void {
		// Collect all events and send them in one final payload.
		$this->buffer[] = $event;
	}

	public function finish(array $finalPayload): void {
       	// Merge buffered events into final payload for debugging/clients that care.
		$payload = [
			'type'		=> 'done',
			'events'	=> $this->buffer,
			'data'		=> $finalPayload,
		];

		echo json_encode($payload);
	}
}
