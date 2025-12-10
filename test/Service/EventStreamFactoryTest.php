<?php declare(strict_types=1);

namespace EventTransport\Test\Service;

use PHPUnit\Framework\TestCase;

class EventStreamFactoryTest extends TestCase {

	public function testCreatesNoStreamWhenModeIsNoStream(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

$config = new class implements \EventTransport\Api\IEventTransportConfig {
	public function getDefaultMode(): string { return 'nostream'; }
	public function isAutoFallbackEnabled(): bool { return false; }
	public function getFallbackOrder(): array { return []; }
};

$factory = new \EventTransport\Service\EventStreamFactory($config);

$stream = $factory->createStream('svc', 'id');
echo get_class($stream);
PHP);

		$this->assertSame(0, $r['exit']);
		$this->assertSame(\EventTransport\Stream\NoStreamEventStream::class, trim($r['stdout']));
	}

	public function testCreatesSseWhenModeIsSse(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

$config = new class implements \EventTransport\Api\IEventTransportConfig {
	public function getDefaultMode(): string { return 'sse'; }
	public function isAutoFallbackEnabled(): bool { return false; }
	public function getFallbackOrder(): array { return []; }
};

$factory = new \EventTransport\Service\EventStreamFactory($config);

$stream = $factory->createStream('svc', 'id');
echo get_class($stream);
PHP);

		$this->assertSame(0, $r['exit']);
		$this->assertSame(\EventTransport\Stream\SseEventStream::class, trim($r['stdout']));
	}

	public function testFallsBackToConfiguredOrderWhenDefaultModeIsUnknown(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

$config = new class implements \EventTransport\Api\IEventTransportConfig {
	public function getDefaultMode(): string { return 'does-not-exist'; }
	public function isAutoFallbackEnabled(): bool { return true; }
	public function getFallbackOrder(): array { return ['short', 'nostream']; }
};

$factory = new \EventTransport\Service\EventStreamFactory($config);

$stream = $factory->createStream('svc', 'id');
echo get_class($stream);
PHP);

		$this->assertSame(0, $r['exit']);
		$this->assertSame(\EventTransport\Stream\ShortPollingEventStream::class, trim($r['stdout']));
	}

	public function testWsModeWithoutResolverFallsBackToNoStream(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

$config = new class implements \EventTransport\Api\IEventTransportConfig {
	public function getDefaultMode(): string { return 'ws'; }
	public function isAutoFallbackEnabled(): bool { return false; }
	public function getFallbackOrder(): array { return []; }
};

$factory = new \EventTransport\Service\EventStreamFactory($config, null);

$stream = $factory->createStream('svc', 'id');
echo get_class($stream);
PHP);

		$this->assertSame(0, $r['exit']);
		$this->assertSame(\EventTransport\Stream\NoStreamEventStream::class, trim($r['stdout']));
	}

	public function testWsModeWithResolverReturningNullFallsBackWhenEnabled(): void {
		$r = $this->runPhp(<<<'PHP'
require getenv('BOOTSTRAP');

$config = new class implements \EventTransport\Api\IEventTransportConfig {
	public function getDefaultMode(): string { return 'ws'; }
	public function isAutoFallbackEnabled(): bool { return true; }
	public function getFallbackOrder(): array { return ['long', 'nostream']; }
};

$resolver = new class implements \EventTransport\Api\IWebSocketConnectionResolver {
	public function resolve(string $serviceName, string $streamId) { return null; }
};

$factory = new \EventTransport\Service\EventStreamFactory($config, $resolver);

$stream = $factory->createStream('svc', 'id');
echo get_class($stream);
PHP);

		$this->assertSame(0, $r['exit']);
		$this->assertSame(\EventTransport\Stream\LongPollingEventStream::class, trim($r['stdout']));
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
