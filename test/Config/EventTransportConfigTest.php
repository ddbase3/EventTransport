<?php declare(strict_types=1);

namespace EventTransport\Test\Config;

use PHPUnit\Framework\TestCase;
use EventTransport\Config\EventTransportConfig;

class EventTransportConfigTest extends TestCase {

	public function testGetDefaultModeReturnsSse(): void {
		$config = new EventTransportConfig();
		$this->assertSame('sse', $config->getDefaultMode());
	}

	public function testIsAutoFallbackEnabledReturnsTrue(): void {
		$config = new EventTransportConfig();
		$this->assertTrue($config->isAutoFallbackEnabled());
	}

	public function testGetFallbackOrderReturnsExpectedOrder(): void {
		$config = new EventTransportConfig();
		$this->assertSame(['short', 'nostream'], $config->getFallbackOrder());
	}

}
