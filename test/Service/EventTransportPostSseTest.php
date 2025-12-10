<?php declare(strict_types=1);

namespace EventTransport\Test\Service;

use PHPUnit\Framework\TestCase;

class EventTransportPostSseTest extends TestCase {

	public function testGetOutputWithMissingIdEmitsErrorEvent(): void {
		$code = <<<'PHP'
require getenv('BOOTSTRAP');

class FakeRequest implements \Base3\Api\IRequest {
	public function get(string $key, $default = null) { return ''; }
	public function post(string $key, $default = null) { return $default; }
	public function request(string $key, $default = null) { return $default; }
	public function allRequest(): array { return []; }
	public function cookie(string $key, $default = null) { return $default; }
	public function session(string $key, $default = null) { return $default; }
	public function server(string $key, $default = null) { return $default; }
	public function files(string $key, $default = null) { return $default; }
	public function allGet(): array { return []; }
	public function allPost(): array { return []; }
	public function allCookie(): array { return []; }
	public function allSession(): array { return []; }
	public function allServer(): array { return []; }
	public function allFiles(): array { return []; }
	public function getJsonBody(): array { return []; }
	public function isCli(): bool { return true; }
	public function getContext(): string { return self::CONTEXT_TEST; }
}

$endpoint = new \EventTransport\Service\EventTransportPostSse(new FakeRequest());
$endpoint->getOutput('html');
PHP;

		$r = $this->runPhp($code);

		$this->assertSame(0, $r['exit']);
		$this->assertStringContainsString("event: error\n", $r['stdout']);
		$this->assertStringContainsString('Invalid or expired stream id', $r['stdout']);
	}

	public function testGetOutputWithInvalidIdEmitsErrorEvent(): void {
		$code = <<<'PHP'
require getenv('BOOTSTRAP');

class FakeRequest implements \Base3\Api\IRequest {
	public function get(string $key, $default = null) { return 'does-not-exist'; }
	public function post(string $key, $default = null) { return $default; }
	public function request(string $key, $default = null) { return $default; }
	public function allRequest(): array { return []; }
	public function cookie(string $key, $default = null) { return $default; }
	public function session(string $key, $default = null) { return $default; }
	public function server(string $key, $default = null) { return $default; }
	public function files(string $key, $default = null) { return $default; }
	public function allGet(): array { return []; }
	public function allPost(): array { return []; }
	public function allCookie(): array { return []; }
	public function allSession(): array { return []; }
	public function allServer(): array { return []; }
	public function allFiles(): array { return []; }
	public function getJsonBody(): array { return []; }
	public function isCli(): bool { return true; }
	public function getContext(): string { return self::CONTEXT_TEST; }
}

$endpoint = new \EventTransport\Service\EventTransportPostSse(new FakeRequest());
$endpoint->getOutput('html');
PHP;

		$r = $this->runPhp($code);

		$this->assertSame(0, $r['exit']);
		$this->assertStringContainsString("event: error\n", $r['stdout']);
		$this->assertStringContainsString('Invalid or expired stream id', $r['stdout']);
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
