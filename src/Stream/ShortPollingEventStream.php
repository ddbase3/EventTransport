<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Short polling stream – events are written to a queue file.
 * A separate HTTP endpoint lets the client poll the next event.
 */
class ShortPollingEventStream implements IEventStream {

	private string $serviceName;
	private string $streamId;
	private string $queueFile;

	private bool $started = false;
	private bool $finished = false;

	public function __construct() {
		// Constructor is DI-only.
	}

	/**
	 * Must be called by the factory before using the stream.
	 */
	public function init(string $serviceName, string $streamId): void {
		$this->serviceName = $serviceName;
		$this->streamId = $streamId;
		$this->queueFile = $this->buildQueueFilePath($serviceName, $streamId);
	}

	public function start(): void {
		// No headers here – short polling uses a separate poll-endpoint.
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
		// Short polling cannot send comments – noop for interface compatibility.
	}

	public function isDisconnected(): bool {
		// The producing side always runs in a normal HTTP request – always connected.
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
	 * Poll the next message – used by a dedicated poll endpoint.
	 * Returns null if no message is available yet.
	 *
	 * @return array<string,mixed>|null
	 */
	public function pollNext(): ?array {
		$queue = $this->loadQueue();
		if (!$queue) {
			return null;
		}

		$next = array_shift($queue);
		$this->saveQueue($queue);

		return $next;
	}

	private function buildQueueFilePath(string $serviceName, string $streamId): string {
		$sanitized = preg_replace('~[^a-zA-Z0-9_\-]~', '_', $serviceName . '_' . $streamId);
		return sys_get_temp_dir() . '/evq_' . $sanitized . '.json';
	}

	/**
	 * @return array<int,array<string,mixed>>
	 */
	private function loadQueue(): array {
		if (!isset($this->queueFile) || !is_file($this->queueFile)) {
			return [];
		}

		$json = file_get_contents($this->queueFile);
		if ($json === false || $json === '') {
			return [];
		}

		$data = json_decode($json, true);
		return is_array($data) ? $data : [];
	}

	/**
	 * @param array<int,array<string,mixed>> $queue
	 */
	private function saveQueue(array $queue): void {
		file_put_contents(
			$this->queueFile,
			json_encode($queue, JSON_UNESCAPED_UNICODE),
			LOCK_EX
		);
	}
}

/*
<?php declare(strict_types=1);

use EventTransport\Stream\ShortPollingEventStream;

// Resolve via DI in echt:
$stream = new ShortPollingEventStream();
$stream->init($_GET['service'] ?? 'default', $_GET['stream'] ?? 'missing');

header('Content-Type: application/json; charset=utf-8');

$next = $stream->pollNext();
if ($next === null) {
	echo json_encode(['type' => 'empty']);
} else {
	echo json_encode($next);
}
 */
