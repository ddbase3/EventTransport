<?php declare(strict_types=1);

namespace EventTransport\Stream;

use PHPUnit\Framework\TestCase;

class SseEventStreamTest extends TestCase {

	public function testStartOutputsSingleNewlineAndIsIdempotent(): void {
		$out = $this->runSnippet(<<<'PHP'
			$stream = new \EventTransport\Stream\SseEventStream();
			$stream->start();
			$stream->start();
PHP);

		self::assertSame("\n", $out);
	}

	public function testPushAutoStartsAndOutputsEventAndJson(): void {
		$out = $this->runSnippet(<<<'PHP'
			$stream = new \EventTransport\Stream\SseEventStream();
			$stream->push('my_event', ['a' => 1]);
PHP);

		self::assertSame(
			"\n" .
			"event: my_event\n" .
			"data: {\"a\":1}\n\n",
			$out
		);
	}

	public function testSendCommentAutoStartsAndOutputsComment(): void {
		$out = $this->runSnippet(<<<'PHP'
			$stream = new \EventTransport\Stream\SseEventStream();
			$stream->sendComment('hello');
PHP);

		self::assertSame(
			"\n" .
			": hello\n\n",
			$out
		);
	}

	public function testFinishAutoStartsOutputsDoneIsIdempotentAndBlocksFurtherOutput(): void {
		$out = $this->runSnippet(<<<'PHP'
			$stream = new \EventTransport\Stream\SseEventStream();

			$stream->finish(['ok' => true]);
			$stream->finish(['ok' => false]); // idempotent

			$stream->push('later', ['x' => 1]); // must not output after finish
			$stream->sendComment('later'); // must not output after finish
PHP);

		self::assertSame(
			"\n" .
			"event: done\n" .
			"data: {\"ok\":true}\n\n",
			$out
		);
	}

	public function testIsDisconnectedIsFalseInCliByDefault(): void {
		$out = $this->runSnippet(<<<'PHP'
			$stream = new \EventTransport\Stream\SseEventStream();
			echo $stream->isDisconnected() ? '1' : '0';
PHP);

		self::assertSame('0', $out);
	}

	private function runSnippet(string $snippet): string {
		$ifaceFile = realpath(__DIR__ . '/../../src/Api/IEventStream.php');
		if ($ifaceFile === false) {
			self::fail('Could not locate IEventStream.php from test path.');
		}

		$streamFile = realpath(__DIR__ . '/../../src/Stream/SseEventStream.php');
		if ($streamFile === false) {
			self::fail('Could not locate SseEventStream.php from test path.');
		}

		$code = ''
			. 'require ' . var_export($ifaceFile, true) . ';'
			. 'require ' . var_export($streamFile, true) . ';'
			. $snippet;

		$cmd = [PHP_BINARY, '-r', $code];

		$descriptors = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$proc = proc_open($cmd, $descriptors, $pipes);
		if (!is_resource($proc)) {
			self::fail('Failed to start PHP subprocess.');
		}

		fclose($pipes[0]);

		$stdout = stream_get_contents($pipes[1]);
		fclose($pipes[1]);

		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[2]);

		$exit = proc_close($proc);

		if ($exit !== 0) {
			self::fail("PHP subprocess failed (exit $exit). STDERR:\n" . $stderr);
		}

		return $stdout === false ? '' : $stdout;
	}
}
