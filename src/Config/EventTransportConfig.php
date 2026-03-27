<?php declare(strict_types=1);

/***********************************************************************
 * This file is part of EventTransport for BASE3 Framework.
 *
 * EventTransport extends the BASE3 framework with a unified event
 * transport layer for streaming and polling between PHP backends
 * and JavaScript frontends.
 *
 * Developed by Daniel Dahme
 * Licensed under GPL-3.0
 * https://www.gnu.org/licenses/gpl-3.0.en.html
 *
 * https://base3.de/v/eventtransport
 * https://github.com/ddbase3/EventTransport
 **********************************************************************/

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
