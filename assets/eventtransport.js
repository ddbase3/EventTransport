/**
 * EventTransport JS client
 * Supports 5 modes: nostream, short, long, sse, ws
 * 
 * Usage:
 * 
 * const cfg = new EventTransportConfig({
 * 	mode: "nostream",
 * 	fallbackModes: ["short", "long"],
 * 	baseHttpUrl: "/event",
 * 	wsUrl: "wss://example.com/event/ws"
 * });
 * 
 * const channel = TransportResolver.createChannel(cfg, "chatbot", "stream123");
 * channel.onMessage(msg => console.log("msg", msg));
 * channel.onOpen(() => console.log("open"));
 * channel.onError(err => console.error(err));
 * channel.connect({ prompt: "Hello" });
 */

export class EventTransportConfig {

	constructor(options = {}) {
		this.mode = options.mode || "nostream";
		this.fallbackModes = options.fallbackModes || ["short", "long", "nostream"];

		this.baseHttpUrl = options.baseHttpUrl || "/event";

		this.endpoints = options.endpoints || {
			nostream: "/nostream",
			short: "/short",
			long: "/long",
			sse: "/sse"
		};

		this.wsUrl = options.wsUrl || null;

		this.shortPollIntervalMs = options.shortPollIntervalMs || 120;
	}

	buildHttpUrl(mode, serviceName, streamId) {
		const base = this.baseHttpUrl + (this.endpoints[mode] || "");
		const params = new URLSearchParams();
		params.set("service", serviceName);
		params.set("stream", streamId);
		return base + "?" + params.toString();
	}

	buildWebSocketUrl(serviceName, streamId) {
		if (!this.wsUrl) {
			return null;
		}
		const params = new URLSearchParams();
		params.set("service", serviceName);
		params.set("stream", streamId);
		return this.wsUrl + "?" + params.toString();
	}
}

/**
 * Base client-side transport abstraction.
 * Concrete transports must implement connect(), send(), close().
 */
export class AbstractTransport {

	constructor(config, serviceName, streamId, options = {}) {
		this.config = config;
		this.serviceName = serviceName;
		this.streamId = streamId;
		this.options = options;

		this._onMessage = null;
		this._onOpen = null;
		this._onError = null;
	}

	onMessage(fn) {
		this._onMessage = fn;
	}

	onOpen(fn) {
		this._onOpen = fn;
	}

	onError(fn) {
		this._onError = fn;
	}

	emitMessage(msg) {
		if (typeof this._onMessage === "function") {
			this._onMessage(msg);
		}
	}

	emitOpen() {
		if (typeof this._onOpen === "function") {
			this._onOpen();
		}
	}

	emitError(err) {
		if (typeof this._onError === "function") {
			this._onError(err);
		}
	}

	// To be implemented by subclasses:
	// async connect(initialPayload) {}
	// async send(data) {}
	// async close() {}
}

/**
 * Non-streaming transport:
 * one POST request, full JSON response once.
 */
export class NoStreamTransport extends AbstractTransport {

	async connect(initialPayload = {}) {
		try {
			const url = this.config.buildHttpUrl("nostream", this.serviceName, this.streamId);
			this.emitOpen();

			const res = await fetch(url, {
				method: "POST",
				headers: {
					"Content-Type": "application/json"
				},
				body: JSON.stringify(initialPayload || {})
			});

			const json = await res.json();
			this.emitMessage(json);
		} catch (e) {
			this.emitError(e);
		}
	}

	async send() {
		console.warn("NoStreamTransport.send() is not supported.");
	}

	async close() {
		// nothing to close
	}
}

/**
 * Short polling transport:
 * - one initial POST to start the server-side stream
 * - then periodic GET /poll to fetch next message
 */
export class ShortPollingTransport extends AbstractTransport {

	constructor(config, serviceName, streamId, options = {}) {
		super(config, serviceName, streamId, options);
		this._running = false;
	}

	async connect(initialPayload = {}) {
		try {
			const url = this.config.buildHttpUrl("short", this.serviceName, this.streamId);
			this.emitOpen();

			// optional: initial POST to start stream
			await fetch(url, {
				method: "POST",
				headers: {
					"Content-Type": "application/json"
				},
				body: JSON.stringify(initialPayload || {})
			});

			this._running = true;
			this.loopPoll();
		} catch (e) {
			this.emitError(e);
		}
	}

	async loopPoll() {
		const url = this.config.buildHttpUrl("short", this.serviceName, this.streamId);
		const delayMs = this.config.shortPollIntervalMs;

		while (this._running) {
			try {
				const res = await fetch(url, {
					method: "GET",
					headers: {
						"Accept": "application/json"
					},
					cache: "no-cache"
				});

				if (!res.ok) {
					throw new Error("ShortPolling HTTP error " + res.status);
				}

				const json = await res.json();
				if (json && json.type && json.type !== "empty") {
					this.emitMessage(json);
					if (json.type === "done") {
						this._running = false;
						break;
					}
				}
			} catch (e) {
				this.emitError(e);
				this._running = false;
				break;
			}

			if (this._running) {
				await this.sleep(delayMs);
			}
		}
	}

	async send() {
		console.warn("ShortPollingTransport.send() is not implemented.");
	}

	async close() {
		this._running = false;
	}

	sleep(ms) {
		return new Promise(resolve => setTimeout(resolve, ms));
	}
}

/**
 * Long polling transport:
 * - client repeatedly does GET requests that block until next event or timeout
 */
export class LongPollingTransport extends AbstractTransport {

	constructor(config, serviceName, streamId, options = {}) {
		super(config, serviceName, streamId, options);
		this._running = false;
	}

	async connect(initialPayload = {}) {
		try {
			const url = this.config.buildHttpUrl("long", this.serviceName, this.streamId);
			this.emitOpen();

			// optional initial POST to start stream
			await fetch(url, {
				method: "POST",
				headers: {
					"Content-Type": "application/json"
				},
				body: JSON.stringify(initialPayload || {})
			});

			this._running = true;
			this.loopLongPoll();
		} catch (e) {
			this.emitError(e);
		}
	}

	async loopLongPoll() {
		const url = this.config.buildHttpUrl("long", this.serviceName, this.streamId);

		while (this._running) {
			try {
				const res = await fetch(url, {
					method: "GET",
					headers: {
						"Accept": "application/json"
					},
					cache: "no-cache"
				});

				if (!res.ok) {
					throw new Error("LongPolling HTTP error " + res.status);
				}

				const json = await res.json();
				if (!json) {
					continue;
				}

				if (json.type === "timeout") {
					// no data in this cycle, continue loop
					continue;
				}

				this.emitMessage(json);
				if (json.type === "done") {
					this._running = false;
					break;
				}
			} catch (e) {
				this.emitError(e);
				this._running = false;
				break;
			}
		}
	}

	async send() {
		console.warn("LongPollingTransport.send() is not implemented.");
	}

	async close() {
		this._running = false;
	}
}

/**
 * SSE (Server-Sent Events) transport.
 * Requires server support for text/event-stream.
 */
export class SseTransport extends AbstractTransport {

	constructor(config, serviceName, streamId, options = {}) {
		super(config, serviceName, streamId, options);
		this._es = null;
	}

	async connect(initialPayload = {}) {
		try {
			const url = this.config.buildHttpUrl("sse", this.serviceName, this.streamId);

			// optional: initial POST to start server stream
			if (initialPayload && Object.keys(initialPayload).length > 0) {
				await fetch(url, {
					method: "POST",
					headers: {
						"Content-Type": "application/json"
					},
					body: JSON.stringify(initialPayload)
				});
			}

			this._es = new EventSource(url);

			this._es.onopen = () => {
				this.emitOpen();
			};

			this._es.onerror = ev => {
				this.emitError(new Error("SSE error"));
			};

			this._es.onmessage = ev => {
				try {
					const data = JSON.parse(ev.data);
					this.emitMessage(data);
				} catch (e) {
					this.emitError(e);
				}
			};
		} catch (e) {
			this.emitError(e);
		}
	}

	async send() {
		console.warn("SseTransport.send() is not supported directly.");
	}

	async close() {
		if (this._es) {
			this._es.close();
			this._es = null;
		}
	}
}

/**
 * WebSocket transport.
 * Requires wsUrl configured in EventTransportConfig.
 */
export class WebSocketTransport extends AbstractTransport {

	constructor(config, serviceName, streamId, options = {}) {
		super(config, serviceName, streamId, options);
		this._ws = null;
	}

	async connect(initialPayload = {}) {
		try {
			const url = this.config.buildWebSocketUrl(this.serviceName, this.streamId);
			if (!url) {
				throw new Error("No WebSocket URL configured.");
			}

			this._ws = new WebSocket(url);

			this._ws.onopen = () => {
				this.emitOpen();
				if (initialPayload && Object.keys(initialPayload).length > 0) {
					this.send({
						type: "init",
						payload: initialPayload
					});
				}
			};

			this._ws.onerror = ev => {
				this.emitError(new Error("WebSocket error"));
			};

			this._ws.onclose = () => {
				// optional: notify close as error or ignore
			};

			this._ws.onmessage = ev => {
				try {
					const data = JSON.parse(ev.data);
					this.emitMessage(data);
				} catch (e) {
					this.emitError(e);
				}
			};
		} catch (e) {
			this.emitError(e);
		}
	}

	async send(data) {
		if (!this._ws || this._ws.readyState !== WebSocket.OPEN) {
			this.emitError(new Error("WebSocket not open."));
			return;
		}
		this._ws.send(JSON.stringify(data || {}));
	}

	async close() {
		if (this._ws) {
			this._ws.close();
			this._ws = null;
		}
	}
}

/**
 * TransportResolver chooses the best transport based on config and environment.
 */
export class TransportResolver {

	/**
	 * Creates a channel for a given service and stream id.
	 * Will try config.mode first, then fallbacks if needed.
	 */
	static createChannel(config, serviceName, streamId, options = {}) {
		const modes = [config.mode, ...(config.fallbackModes || [])];
		const tried = new Set();

		for (const mode of modes) {
			if (!mode || tried.has(mode)) {
				continue;
			}
			tried.add(mode);
			const t = this.createTransportByMode(mode, config, serviceName, streamId, options);
			if (t) {
				return t;
			}
		}

		// final fallback
		return new NoStreamTransport(config, serviceName, streamId, options);
	}

	static createTransportByMode(mode, config, serviceName, streamId, options = {}) {
		switch (mode) {
			case "nostream":
				return new NoStreamTransport(config, serviceName, streamId, options);

			case "short":
				return new ShortPollingTransport(config, serviceName, streamId, options);

			case "long":
				return new LongPollingTransport(config, serviceName, streamId, options);

			case "sse":
				if (typeof EventSource === "undefined") {
					return null;
				}
				return new SseTransport(config, serviceName, streamId, options);

			case "ws":
				if (typeof WebSocket === "undefined") {
					return null;
				}
				return new WebSocketTransport(config, serviceName, streamId, options);

			default:
				return null;
		}
	}
}

