<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Long polling variant – the server writes events to a file-backed queue,
 * and a long-poll endpoint consumes a single event with blocking wait (up to timeout).
 */
class LongPollingEventStream implements IEventStream {

	private string $serviceName;
	private string $streamId;
	private string $queueFile;

	private bool $started = false;
	private bool $finished = false;

	public function __construct() {
		// DI-only constructor
	}

	/**
	 * Must be injected by factory or resolver.
	 */
	public function init(string $serviceName, string $streamId): void {
		$this->serviceName = $serviceName;
		$this->streamId = $streamId;
		$this->queueFile = $this->buildQueuePath($serviceName, $streamId);
	}

	public function start(): void {
		// No headers here – delivered via long-poll endpoint
		$this->started = true;
	}

	public function push(string $event, array $data): void {
		if ($this->finished) return;
		if (!$this->started) $this->start();

		$queue = $this->loadQueue();
		$queue[] = [
			'type' => $event,
			'data' => $data
		];
		$this->saveQueue($queue);
	}

	public function sendComment(string $text): void {
		// Long polling cannot send comments – noop for API compatibility
	}

	public function isDisconnected(): bool {
		// For long-poll producer side: always considered connected
		return false;
	}

	public function finish(array $finalPayload): void {
		if ($this->finished) return;
		$this->finished = true;

		if (!$this->started) $this->start();

		$queue = $this->loadQueue();
		$queue[] = [
			'type' => 'done',
			'data' => $finalPayload
		];
		$this->saveQueue($queue);
	}

	/**
	 * Long-poll endpoint helper: waits for next event or timeout.
	 *
	 * @return array<string,mixed>|null
	 */
	public function waitNext(int $timeoutSeconds = 20): ?array {
		$start = microtime(true);

		while (true) {
			$queue = $this->loadQueue();

			if (!empty($queue)) {
				$next = array_shift($queue);
				$this->saveQueue($queue);
				return $next;
			}

			if ((microtime(true) - $start) > $timeoutSeconds) {
				return [
					'type' => 'timeout'
				];
			}

			usleep(50000); // 50ms
		}
	}

	private function buildQueuePath(string $serviceName, string $streamId): string {
		$base = preg_replace('~[^a-zA-Z0-9_\-]~', '_', $serviceName . '_' . $streamId);
		return sys_get_temp_dir() . '/evq_long_' . $base . '.json';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function loadQueue(): array {
		if (!is_file($this->queueFile)) {
			return [];
		}
		$json = file_get_contents($this->queueFile);
		if ($json === false) {
			return [];
		}
		return json_decode($json, true) ?: [];
	}

	/**
	 * @param array<int,array<string,mixed>> $queue
	 */
	private function saveQueue(array $queue): void {
		// Write atomically to avoid race conditions
		file_put_contents($this->queueFile, json_encode($queue, JSON_UNESCAPED_UNICODE), LOCK_EX);
	}
}
