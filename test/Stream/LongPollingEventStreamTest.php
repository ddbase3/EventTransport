<?php declare(strict_types=1);

namespace EventTransport\Stream;

use PHPUnit\Framework\TestCase;

class LongPollingEventStreamTest extends TestCase {

	private ?string $queueFile = null;

	protected function tearDown(): void {
		if ($this->queueFile !== null && is_file($this->queueFile)) {
			@unlink($this->queueFile);
		}
	}

	public function testPushWritesEventAndWaitNextConsumesImmediately(): void {
		$service = 'svc/with spaces';
		$streamId = 'stream#1';

		$stream = new LongPollingEventStream();
		$stream->init($service, $streamId);

		$this->queueFile = $this->buildExpectedQueuePath($service, $streamId);
		@unlink($this->queueFile);

		$stream->push('my_event', ['a' => 1]);

		self::assertFileExists($this->queueFile);

		$next = $stream->waitNext(20); // queue is non-empty -> returns immediately, no sleeping
		self::assertIsArray($next);
		self::assertSame('my_event', $next['type']);
		self::assertSame(['a' => 1], $next['data']);

		// queue is empty now
		$timeout = $stream->waitNext(-1); // immediate timeout path, no usleep
		self::assertSame('timeout', $timeout['type']);
	}

	public function testFinishAppendsDoneAndIsIdempotent(): void {
		$service = 'service';
		$streamId = 'stream';

		$stream = new LongPollingEventStream();
		$stream->init($service, $streamId);

		$this->queueFile = $this->buildExpectedQueuePath($service, $streamId);
		@unlink($this->queueFile);

		$stream->finish(['ok' => true]);
		$stream->finish(['ok' => false]); // must not append again

		$done = $stream->waitNext(20); // immediate, queue non-empty
		self::assertSame('done', $done['type']);
		self::assertSame(['ok' => true], $done['data']);

		$timeout = $stream->waitNext(-1); // immediate timeout path
		self::assertSame('timeout', $timeout['type']);
	}

	public function testPushDoesNothingAfterFinish(): void {
		$service = 'service';
		$streamId = 'stream';

		$stream = new LongPollingEventStream();
		$stream->init($service, $streamId);

		$this->queueFile = $this->buildExpectedQueuePath($service, $streamId);
		@unlink($this->queueFile);

		$stream->finish(['final' => 1]);
		$stream->push('later_event', ['x' => 1]); // must be ignored

		$done = $stream->waitNext(20);
		self::assertSame('done', $done['type']);

		$timeout = $stream->waitNext(-1);
		self::assertSame('timeout', $timeout['type']);
	}

	public function testSendCommentIsNoopAndIsDisconnectedIsFalse(): void {
		$stream = new LongPollingEventStream();
		$stream->init('service', 'stream');

		$this->queueFile = $this->buildExpectedQueuePath('service', 'stream');
		@unlink($this->queueFile);

		$stream->sendComment('hello');
		self::assertFalse($stream->isDisconnected());
	}

	private function buildExpectedQueuePath(string $serviceName, string $streamId): string {
		$base = preg_replace('~[^a-zA-Z0-9_\-]~', '_', $serviceName . '_' . $streamId);
		return sys_get_temp_dir() . '/evq_long_' . $base . '.json';
	}
}
