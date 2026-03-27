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

namespace EventTransport;

use Base3\Api\IContainer;
use Base3\Api\IPlugin;
use EventTransport\Api\IEventTransportConfig;
use EventTransport\Api\IEventStreamFactory;
use EventTransport\Api\IWebSocketConnectionResolver;
use EventTransport\Config\EventTransportConfig;
use EventTransport\Service\EventStreamFactory;
use EventTransport\Service\NullWebSocketConnectionResolver;

class EventTransportPlugin implements IPlugin {

	public function __construct(private readonly IContainer $container) {}

	// Implementation of IBase

	public static function getName(): string {
		return "eventtransportplugin";
	}

	// Implementation of IPlugin

	public function init() {
		$this->container
			->set(self::getName(), $this, IContainer::SHARED)

			->set(IEventTransportConfig::class, fn() => new EventTransportConfig(), IContainer::SHARED)
			->set(IWebSocketConnectionResolver::class, fn() => new NullWebSocketConnectionResolver(), IContainer::SHARED)
			->set(IEventStreamFactory::class, fn($c) => new EventStreamFactory(
				$c->get(IEventTransportConfig::class),
				$c->get(IWebSocketConnectionResolver::class)
			), IContainer::SHARED);
	}
}
