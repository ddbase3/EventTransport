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
