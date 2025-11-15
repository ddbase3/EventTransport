<?php declare(strict_types=1);

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
