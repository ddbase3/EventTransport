<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Long polling variant – the server writes events to a queue,
 * and a dedicated long-poll endpoint consumes a single event
 * with blocking wait (up to timeout).
 */
class LongPollingEventStream implements IEventStream {

	private string $serviceName;
	private string $streamId;
	private string $queueFile;

	public function __construct() {
		// DI-only constructor
	}

	public function init(string $serviceName, string $streamId): void {
		$this->serviceName = $serviceName;
		$this->streamId = $streamId;
		$this->queueFile = $this->buildQueuePath($serviceName, $streamId);
	}

	public function start(): void {
		// No headers here – delivered via long-poll endpoint
	}

	public function push(array $event): void {
		$queue = $this->loadQueue();
		$queue[] = $event;
		$this->saveQueue($queue);
	}

	public function finish(array $finalPayload): void {
		$queue = $this->loadQueue();
		$queue[] = [
			'type'	=> 'done',
			'data'	=> $finalPayload,
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
		return json_decode(file_get_contents($this->queueFile) ?: '[]', true) ?: [];
	}

	/**
	 * @param array<int,array<string,mixed>> $queue
	 */
	private function saveQueue(array $queue): void {
		file_put_contents($this->queueFile, json_encode($queue));
	}
}
