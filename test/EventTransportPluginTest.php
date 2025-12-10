<?php declare(strict_types=1);

namespace EventTransport\Test;

use PHPUnit\Framework\TestCase;
use EventTransport\EventTransportPlugin;
use Base3\Api\IContainer;
use EventTransport\Api\IEventTransportConfig;
use EventTransport\Api\IWebSocketConnectionResolver;
use EventTransport\Api\IEventStreamFactory;
use EventTransport\Config\EventTransportConfig;
use EventTransport\Service\NullWebSocketConnectionResolver;
use EventTransport\Service\EventStreamFactory;

class EventTransportPluginTest extends TestCase {

	public function testGetNameReturnsExpectedValue(): void {
		$this->assertSame('eventtransportplugin', EventTransportPlugin::getName());
	}

	public function testInitRegistersServicesAsShared(): void {
		$container = new FakeContainer();
		$plugin = new EventTransportPlugin($container);

		$plugin->init();

		$this->assertTrue($container->has(EventTransportPlugin::getName()));
		$this->assertSame(IContainer::SHARED, $container->getFlags(EventTransportPlugin::getName()));

		$this->assertTrue($container->has(IEventTransportConfig::class));
		$this->assertSame(IContainer::SHARED, $container->getFlags(IEventTransportConfig::class));

		$this->assertTrue($container->has(IWebSocketConnectionResolver::class));
		$this->assertSame(IContainer::SHARED, $container->getFlags(IWebSocketConnectionResolver::class));

		$this->assertTrue($container->has(IEventStreamFactory::class));
		$this->assertSame(IContainer::SHARED, $container->getFlags(IEventStreamFactory::class));
	}

	public function testFactoriesReturnExpectedImplementations(): void {
		$container = new FakeContainer();
		$plugin = new EventTransportPlugin($container);

		$plugin->init();

		$config = $container->get(IEventTransportConfig::class);
		$this->assertInstanceOf(EventTransportConfig::class, $config);

		$resolver = $container->get(IWebSocketConnectionResolver::class);
		$this->assertInstanceOf(NullWebSocketConnectionResolver::class, $resolver);

		$factory = $container->get(IEventStreamFactory::class);
		$this->assertInstanceOf(EventStreamFactory::class, $factory);
	}

}

class FakeContainer implements IContainer {

	private array $items = [];
	private array $flags = [];
	private array $shared = [];

	public function getServiceList(): array {
		return array_keys($this->items);
	}

	public function set(string $name, $classDefinition, $flags = 0): IContainer {
		$this->items[$name] = $classDefinition;
		$this->flags[$name] = (int)$flags;
		unset($this->shared[$name]);
		return $this;
	}

	public function remove(string $name) {
		unset($this->items[$name], $this->flags[$name], $this->shared[$name]);
	}

	public function has(string $name): bool {
		return array_key_exists($name, $this->items);
	}

	public function get(string $name) {
		if (!array_key_exists($name, $this->items)) {
			return null;
		}

		$flags = $this->flags[$name] ?? 0;

		// Return cached shared instances
		if (($flags & IContainer::SHARED) && array_key_exists($name, $this->shared)) {
			return $this->shared[$name];
		}

		$entry = $this->items[$name];

		// Resolve factory closures
		if (is_callable($entry)) {
			$ref = new \ReflectionFunction(\Closure::fromCallable($entry));
			$value = $ref->getNumberOfParameters() > 0 ? $entry($this) : $entry();
		} else {
			$value = $entry;
		}

		if ($flags & IContainer::SHARED) {
			$this->shared[$name] = $value;
		}

		return $value;
	}

	public function getFlags(string $name): ?int {
		return $this->flags[$name] ?? null;
	}

}
