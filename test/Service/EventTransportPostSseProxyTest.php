<?php declare(strict_types=1);

namespace EventTransport\Test\Service;

use PHPUnit\Framework\TestCase;

class EventTransportPostSseProxyTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('eventtransportpostsseproxy', \EventTransport\Service\EventTransportPostSseProxy::getName());
	}

	public function testGetOutputWithEmptyInputReturnsInvalidJsonError(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

class FakeRequest implements \Base3\Api\IRequest {
	public function get(string $key, $default = null) { return $default; }
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

$proxy = new \EventTransport\Service\EventTransportPostSseProxy(new FakeRequest());
echo $proxy->getOutput('html');
PHP);

		$this->assertSame(0, $r['exit']);

		$data = json_decode(trim($r['stdout']), true);
		$this->assertIsArray($data);
		$this->assertFalse($data['ok'] ?? true);
		$this->assertSame('Invalid JSON payload', $data['error'] ?? null);
	}

	public function testGetHelpReturnsString(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

class FakeRequest implements \Base3\Api\IRequest {
	public function get(string $key, $default = null) { return $default; }
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

$proxy = new \EventTransport\Service\EventTransportPostSseProxy(new FakeRequest());
echo $proxy->getHelp();
PHP);

		$this->assertSame(0, $r['exit']);
		$this->assertSame('POST JSON {endpoint, payload} und erhalte {ok, id, stream}.', trim($r['stdout']));
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
