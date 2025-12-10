<?php declare(strict_types=1);

namespace EventTransport\Test\Stream;

use PHPUnit\Framework\TestCase;

class SseEventStreamTest extends TestCase {

	public function testPushOutputsEventAndJson(): void {
		$code = <<<'PHP'
require getenv('BOOTSTRAP');
$stream = new \EventTransport\Stream\SseEventStream();
$stream->push('message', ['text' => 'Hello', 'n' => 1]);
PHP;

		$r = $this->runPhp($code);

		$this->assertSame(0, $r['exit']);
		$this->assertStringContainsString("event: message\n", $r['stdout']);
		$this->assertStringContainsString('"text":"Hello"', $r['stdout']);
		$this->assertStringContainsString('"n":1', $r['stdout']);
	}

	public function testSendCommentOutputsCommentLine(): void {
		$code = <<<'PHP'
require getenv('BOOTSTRAP');
$stream = new \EventTransport\Stream\SseEventStream();
$stream->sendComment('heartbeat');
PHP;

		$r = $this->runPhp($code);

		$this->assertSame(0, $r['exit']);
		$this->assertStringContainsString(": heartbeat\n\n", $r['stdout']);
	}

	public function testFinishOutputsDoneEventOnce(): void {
		$code = <<<'PHP'
require getenv('BOOTSTRAP');
$stream = new \EventTransport\Stream\SseEventStream();
$stream->finish(['ok' => true]);
$stream->finish(['ok' => false]);
PHP;

		$r = $this->runPhp($code);

		$this->assertSame(0, $r['exit']);
		$this->assertStringContainsString("event: done\n", $r['stdout']);
		$this->assertStringContainsString('"ok":true', $r['stdout']);
	}

	private function runPhp(string $code): array {
		$bootstrap = DIR_ROOT . 'test/bootstrap.php';

		$cmd = [PHP_BINARY, '-d', 'display_errors=1', '-d', 'error_reporting=E_ALL', '-r', $code];

		$spec = [
			0 => ['pipe', 'r'],
			1 => ['pipe', 'w'],
			2 => ['pipe', 'w'],
		];

		$env = $_ENV;
		$env['BOOTSTRAP'] = $bootstrap;

		$p = proc_open($cmd, $spec, $pipes, DIR_ROOT, $env);
		if (!is_resource($p)) {
			$this->fail('Could not start PHP subprocess');
		}

		fclose($pipes[0]);
		$stdout = stream_get_contents($pipes[1]);
		$stderr = stream_get_contents($pipes[2]);
		fclose($pipes[1]);
		fclose($pipes[2]);

		$exit = proc_close($p);

		return ['exit' => $exit, 'stdout' => (string)$stdout, 'stderr' => (string)$stderr];
	}

}
