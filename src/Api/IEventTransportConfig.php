<?php declare(strict_types=1);

namespace EventTransport\Api;

/**
 * Global transport configuration for the project.
 * Decides which transport mode is used for all services by default.
 */
interface IEventTransportConfig {

	/**
	 * Returns the globally configured default mode.
	 * 
	 * Supported values: "nostream", "short", "long", "sse", "ws"
	 */
	public function getDefaultMode(): string;

	/**
	 * Whether fallback should be used if a mode is not available.
	 */
	public function isAutoFallbackEnabled(): bool;

	/**
	 * Returns ordered list of fallback modes.
	 * 
	 * @return string[]
	 */
	public function getFallbackOrder(): array;
}
