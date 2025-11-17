<?php declare(strict_types=1);

namespace EventTransport\Config;

use EventTransport\Api\IEventTransportConfig;

/**
 * Default implementation – later you can load this from Base3 config.
 */
class EventTransportConfig implements IEventTransportConfig {

	public function getDefaultMode(): string {
		// Example: Hetzner Webhosting
		return 'sse';
	}

	public function isAutoFallbackEnabled(): bool {
		return true;
	}

	public function getFallbackOrder(): array {
		// Try short polling first, then fallback to non-stream
		return ['short', 'nostream'];
	}
}
