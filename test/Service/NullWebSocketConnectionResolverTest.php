<?php declare(strict_types=1);

namespace EventTransport\Service;

use EventTransport\Api\IWebSocketConnectionResolver;
use PHPUnit\Framework\TestCase;

class NullWebSocketConnectionResolverTest extends TestCase {

	public function testImplementsInterface(): void {
		$resolver = new NullWebSocketConnectionResolver();

		self::assertInstanceOf(IWebSocketConnectionResolver::class, $resolver);
	}

	public function testResolveAlwaysReturnsNull(): void {
		$resolver = new NullWebSocketConnectionResolver();

		$result1 = $resolver->resolve('any-service', 'any-stream');
		self::assertNull($result1);

		// Also verify with other values to ensure it never changes behavior
		$result2 = $resolver->resolve('another-service', 'stream-123');
		self::assertNull($result2);
	}
}
