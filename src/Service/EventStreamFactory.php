<?php declare(strict_types=1);

namespace EventTransport\Service;

use EventTransport\Api\IEventStream;
use EventTransport\Api\IEventStreamFactory;
use EventTransport\Api\IEventTransportConfig;
use EventTransport\Api\IWebSocketConnectionResolver;
use EventTransport\Stream\NoStreamEventStream;
use EventTransport\Stream\ShortPollingEventStream;
use EventTransport\Stream\LongPollingEventStream;
use EventTransport\Stream\SseEventStream;
use EventTransport\Stream\WebSocketEventStream;

/**
 * Central factory that creates event streams based on global transport config.
 * Supports all 5 transport modes:
 *  - nostream
 *  - short
 *  - long
 *  - sse
 *  - ws
 */
class EventStreamFactory implements IEventStreamFactory {

	private IEventTransportConfig $config;

	/**
	 * Optional: available only if WebSocket mode is supported.
	 */
	private ?IWebSocketConnectionResolver $wsResolver = null;

	public function __construct(IEventTransportConfig $config, ?IWebSocketConnectionResolver $wsResolver = null) {
		$this->config = $config;
		$this->wsResolver = $wsResolver;
	}

	public function createStream(string $serviceName, string $streamId): IEventStream {
		$mode = $this->config->getDefaultMode();

		$stream = $this->createByMode($mode, $serviceName, $streamId);
		if ($stream !== null) {
			return $stream;
		}

		if ($this->config->isAutoFallbackEnabled()) {
			foreach ($this->config->getFallbackOrder() as $fallbackMode) {
				$stream = $this->createByMode($fallbackMode, $serviceName, $streamId);
				if ($stream !== null) {
					return $stream;
				}
			}
		}

		// Final fallback – always safe
		return new NoStreamEventStream();
	}

	/**
	 * Creates a concrete transport instance depending on mode.
	 * Returns null if the mode is not available.
	 */
	private function createByMode(string $mode, string $serviceName, string $streamId): ?IEventStream {
		switch ($mode) {

			case 'nostream': {
				$ns = new NoStreamEventStream();
				return $ns;
			}

			case 'short': {
				$sp = new ShortPollingEventStream();
				$sp->init($serviceName, $streamId);
				return $sp;
			}

			case 'long': {
				$lp = new LongPollingEventStream();
				$lp->init($serviceName, $streamId);
				return $lp;
			}

			case 'sse': {
				// Real streaming only possible if environment supports SSE
				// Factory returns the stream, success depends on server config.
				$sse = new SseEventStream();
				return $sse;
			}

			case 'ws': {
				// WebSocket requires a connection resolver
				if ($this->wsResolver === null) {
					return null;
				}
				$connection = $this->wsResolver->resolve($serviceName, $streamId);
				if ($connection === null) {
					return null;
				}

				$ws = new WebSocketEventStream();
				$ws->init($connection);
				return $ws;
			}

			default:
				return null;
		}
	}
}
