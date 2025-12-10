<?php declare(strict_types=1);

namespace EventTransport\Test\Stream;

use PHPUnit\Framework\TestCase;
use EventTransport\Stream\NoStreamEventStream;

class NoStreamEventStreamTest extends TestCase {

	public function testPushBuffersEventsAndFinishEmitsSingleJsonPayload(): void {
		$stream = new NoStreamEventStream();

		$stream->push('message', ['text' => 'Hello']);
		$stream->push('meta', ['n' => 1]);

		ob_start();
		$stream->finish(['ok' => true]);
		$out = ob_get_clean();

		$this->assertNotSame('', $out);

		$payload = json_decode($out, true);
		$this->assertIsArray($payload);

		$this->assertSame('done', $payload['type'] ?? null);
		$this->assertSame(['ok' => true], $payload['data'] ?? null);

		$this->assertIsArray($payload['events'] ?? null);
		$this->assertCount(2, $payload['events']);

		$this->assertSame('message', $payload['events'][0]['type'] ?? null);
		$this->assertSame(['text' => 'Hello'], $payload['events'][0]['data'] ?? null);

		$this->assertSame('meta', $payload['events'][1]['type'] ?? null);
		$this->assertSame(['n' => 1], $payload['events'][1]['data'] ?? null);
	}

	public function testFinishIsIdempotent(): void {
		$stream = new NoStreamEventStream();

		ob_start();
		$stream->finish(['ok' => true]);
		$first = ob_get_clean();

		ob_start();
		$stream->finish(['ok' => false]);
		$second = ob_get_clean();

		$this->assertNotSame('', $first);
		$this->assertSame('', $second);
	}

	public function testPushAfterFinishDoesNothing(): void {
		$stream = new NoStreamEventStream();

		ob_start();
		$stream->finish(['ok' => true]);
		ob_get_clean();

		$stream->push('message', ['x' => 1]);

		ob_start();
		$stream->finish(['ok' => true]);
		$out = ob_get_clean();

		// No second emission
		$this->assertSame('', $out);
	}

	public function testIsDisconnectedAlwaysFalse(): void {
		$stream = new NoStreamEventStream();
		$this->assertFalse($stream->isDisconnected());
	}

	public function testSendCommentIsNoOp(): void {
		$stream = new NoStreamEventStream();

		ob_start();
		$stream->sendComment('heartbeat');
		$out = ob_get_clean();

		$this->assertSame('', $out);
	}

}
