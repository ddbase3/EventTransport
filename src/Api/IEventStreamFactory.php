<?php declare(strict_types=1);

namespace EventTransport\Api;

/**
 * Factory for creating event streams for a given service and stream ID.
 */
interface IEventStreamFactory {

	/**
	 * Creates a new stream instance for a given service and stream ID.
	 * 
	 * @param string $serviceName Logical service identifier (e.g. "chatbot", "widget_xyz").
	 * @param string $streamId Unique stream id within the service (e.g. UUID).
	 */
	public function createStream(string $serviceName, string $streamId): IEventStream;
}
