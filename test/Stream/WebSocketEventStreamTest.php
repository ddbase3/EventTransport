<?php declare(strict_types=1);

namespace EventTransport\Stream;

use PHPUnit\Framework\TestCase;

class WebSocketEventStreamTest extends TestCase {

	public function testIsDisconnectedTrueWhenNoConnectionInjected(): void {
		$stream = new WebSocketEventStream();
		self::assertTrue($stream->isDisconnected());
	}

	public function testIsDisconnectedUsesIsConnectedWhenAvailable(): void {
		$stream = new WebSocketEventStream();

		$connDisconnected = new class {
			public function isConnected(): bool { return false; }
			public function send(string $json): void { /* noop */ }
		};

		$stream->init($connDisconnected);
		self::assertTrue($stream->isDisconnected());

		$stream2 = new WebSocketEventStream();
		$connConnected = new class {
			public array $sent = [];
			public function isConnected(): bool { return true; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream2->init($connConnected);
		self::assertFalse($stream2->isDisconnected());
	}

	public function testIsDisconnectedUsesGetStatusWhenAvailable(): void {
		$stream = new WebSocketEventStream();

		$connClosed = new class {
			public function getStatus(): int { return 4; }
			public function send(string $json): void { /* noop */ }
		};

		$stream->init($connClosed);
		self::assertTrue($stream->isDisconnected());

		$stream2 = new WebSocketEventStream();
		$connOpen = new class {
			public function getStatus(): int { return 1; }
			public function send(string $json): void { /* noop */ }
		};

		$stream2->init($connOpen);
		self::assertFalse($stream2->isDisconnected());
	}

	public function testIsDisconnectedAssumesActiveWhenNoKnownMethodExists(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);
		self::assertFalse($stream->isDisconnected());
	}

	public function testPushSendsJsonAndAutoStarts(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function isConnected(): bool { return true; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);
		$stream->push('my_event', ['a' => 1]);

		self::assertCount(1, $conn->sent);
		$decoded = json_decode($conn->sent[0], true);
		self::assertSame(['type' => 'my_event', 'data' => ['a' => 1]], $decoded);

		self::assertTrue($this->getPrivateBool($stream, 'started'));
		self::assertFalse($this->getPrivateBool($stream, 'finished'));
	}

	public function testPushDoesNothingWhenFinished(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function isConnected(): bool { return true; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);
		$stream->finish(['x' => 1]);
		$stream->push('later', ['y' => 2]);

		self::assertCount(1, $conn->sent);
		$decoded = json_decode($conn->sent[0], true);
		self::assertSame('done', $decoded['type']);
	}

	public function testPushDoesNothingWhenDisconnected(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function isConnected(): bool { return false; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);
		$stream->push('e', ['x' => 1]);

		self::assertCount(0, $conn->sent);
		self::assertTrue($this->getPrivateBool($stream, 'started'));
	}

	public function testSendCommentSendsStructuredComment(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function isConnected(): bool { return true; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);
		$stream->sendComment('hello');

		self::assertCount(1, $conn->sent);
		$decoded = json_decode($conn->sent[0], true);

		self::assertSame('comment', $decoded['type']);
		self::assertSame(['text' => 'hello'], $decoded['data']);
	}

	public function testSendCommentDoesNothingWhenDisconnectedOrFinished(): void {
		$stream = new WebSocketEventStream();

		$connDisconnected = new class {
			public array $sent = [];
			public function isConnected(): bool { return false; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($connDisconnected);
		$stream->sendComment('nope');
		self::assertCount(0, $connDisconnected->sent);

		$stream2 = new WebSocketEventStream();
		$connConnected = new class {
			public array $sent = [];
			public function isConnected(): bool { return true; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream2->init($connConnected);
		$stream2->finish(['ok' => true]);
		$stream2->sendComment('later');

		self::assertCount(1, $connConnected->sent);
	}

	public function testFinishSendsDoneOnceAndIsIdempotent(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function isConnected(): bool { return true; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);

		$stream->finish(['final' => 1]);
		$stream->finish(['final' => 2]);

		self::assertTrue($this->getPrivateBool($stream, 'started'));
		self::assertTrue($this->getPrivateBool($stream, 'finished'));

		self::assertCount(1, $conn->sent);
		$decoded = json_decode($conn->sent[0], true);

		self::assertSame('done', $decoded['type']);
		self::assertSame(['final' => 1], $decoded['data']);
	}

	public function testFinishDoesNotSendWhenDisconnected(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public array $sent = [];
			public function isConnected(): bool { return false; }
			public function send(string $json): void { $this->sent[] = $json; }
		};

		$stream->init($conn);
		$stream->finish(['final' => 1]);

		self::assertCount(0, $conn->sent);
		self::assertTrue($this->getPrivateBool($stream, 'finished'));
	}

	public function testSendJsonSwallowsSendExceptions(): void {
		$stream = new WebSocketEventStream();

		$conn = new class {
			public function isConnected(): bool { return true; }
			public function send(string $json): void { throw new \RuntimeException('send failed'); }
		};

		$stream->init($conn);

		$stream->push('e', ['x' => 1]);
		$stream->finish(['y' => 2]);

		self::assertTrue($this->getPrivateBool($stream, 'started'));
		self::assertTrue($this->getPrivateBool($stream, 'finished'));
	}

	private function getPrivateBool(object $obj, string $prop): bool {
		$rp = new \ReflectionProperty($obj, $prop);
		$rp->setAccessible(true);
		$val = $rp->getValue($obj);
		self::assertIsBool($val);
		return $val;
	}
}
