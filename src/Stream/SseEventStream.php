<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Server-Sent Events stream (SSE).
 * Real streaming if the server supports it.
 */
class SseEventStream implements IEventStream {

	public function __construct() {
		// DI-only constructor
	}

	public function start(): void {
		if (!headers_sent()) {
			header('Content-Type: text/event-stream; charset=utf-8');
			header('Cache-Control: no-cache, no-store, must-revalidate');
			header('X-Accel-Buffering: no');
		}
		flush();
	}

	public function push(array $event): void {
		echo 'data: ' . json_encode($event) . "\n\n";
		flush();
	}

	public function finish(array $finalPayload): void {
		echo 'data: ' . json_encode([
			'type' => 'done',
			'data' => $finalPayload
		]) . "\n\n";

		flush();
	}
}
