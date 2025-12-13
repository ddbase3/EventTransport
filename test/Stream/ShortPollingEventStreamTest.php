<?php declare(strict_types=1);

namespace EventTransport\Stream;

use PHPUnit\Framework\TestCase;

class ShortPollingEventStreamTest extends TestCase {

	private ?string $queueFile = null;

	protected function tearDown(): void {
		if ($this->queueFile !== null && is_file($this->queueFile)) {
			@unlink($this->queueFile);
		}
	}

	public function testPollNextReturnsNullWhenNoFileExists(): void {
		$stream = new ShortPollingEventStream();
		$stream->init('service', 'stream');

		$this->queueFile = $this->buildExpectedQueuePath('service', 'stream');
		@unlink($this->queueFile);

		self::assertNull($stream->pollNext());
	}

	public function testPushAutoStartsAndPollNextConsumesInOrder(): void {
		$service = 'svc/with spaces';
		$streamId = 'stream#1';

		$stream = new ShortPollingEventStream();
		$stream->init($service, $streamId);

		$this->queueFile = $this->buildExpectedQueuePath($service, $streamId);
		@unlink($this->queueFile);

		$stream->push('e1', ['n' => 1]);
		$stream->push('e2', ['n' => 2]);

		$first = $stream->pollNext();
		self::assertIsArray($first);
		self::assertSame('e1', $first['type']);
		self::assertSame(['n' => 1], $first['data']);

		$second = $stream->pollNext();
		self::assertIsArray($second);
		self::assertSame('e2', $second['type']);
		self::assertSame(['n' => 2], $second['data']);

		self::assertNull($stream->pollNext());
	}

	public function testFinishAppendsDoneAndIsIdempotent(): void {
		$stream = new ShortPollingEventStream();
		$stream->init('service', 'stream');

		$this->queueFile = $this->buildExpectedQueuePath('service', 'stream');
		@unlink($this->queueFile);

		$stream->finish(['ok' => true]);
		$stream->finish(['ok' => false]); // must not append again

		$done = $stream->pollNext();
		self::assertIsArray($done);
		self::assertSame('done', $done['type']);
		self::assertSame(['ok' => true], $done['data']);

		self::assertNull($stream->pollNext());
	}

	public function testPushDoesNothingAfterFinish(): void {
		$stream = new ShortPollingEventStream();
		$stream->init('service', 'stream');

		$this->queueFile = $this->buildExpectedQueuePath('service', 'stream');
		@unlink($this->queueFile);

		$stream->finish(['final' => 1]);
		$stream->push('later_event', ['x' => 1]); // must be ignored

		$done = $stream->pollNext();
		self::assertSame('done', $done['type']);

		self::assertNull($stream->pollNext());
	}

	public function testSendCommentIsNoopAndIsDisconnectedIsFalse(): void {
		$stream = new ShortPollingEventStream();
		$stream->init('service', 'stream');

		$this->queueFile = $this->buildExpectedQueuePath('service', 'stream');
		@unlink($this->queueFile);

		$stream->sendComment('hello');
		self::assertFalse($stream->isDisconnected());
	}

	private function buildExpectedQueuePath(string $serviceName, string $streamId): string {
		$sanitized = preg_replace('~[^a-zA-Z0-9_\-]~', '_', $serviceName . '_' . $streamId);
		return sys_get_temp_dir() . '/evq_' . $sanitized . '.json';
	}
}
