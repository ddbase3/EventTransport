// EventTransportClient.js
// Supports: REST, SSE, WebSocket, POST-SSE
// Uses user-provided event list for SSE

class EventTransportClient {
	constructor(options = {}) {
		this.options = Object.assign({
			endpoint: null,
			transport: "auto",		// auto | rest | sse | ws | postsse
			events: [],				// custom SSE events
			payload: null			// request payload (prompt, reference, etc.)
		}, options);

		this.onEventCallback = null;
		this.activeTransport = null;

		this._es = null;
		this._ws = null;
	}

	_ensureCallback() {
		if (typeof this.onEventCallback !== "function") {
			this.onEventCallback = () => {};
		}
	}

	async connect(callback) {
		this.onEventCallback = callback;
		this._ensureCallback();

		// Explicit override
		if (this.options.transport === "sse") {
			return this._connectSSE();
		}
		if (this.options.transport === "postsse") {
			return this._connectPostSSE();
		}
		if (this.options.transport === "ws") {
			return this._connectWS();
		}
		if (this.options.transport === "rest") {
			this.activeTransport = "rest";
			return;
		}

		// Auto detection logic
		if (typeof this.options.endpoint === "string" && this.options.endpoint.startsWith("ws")) {
			try {
				return await this._connectWS();
			} catch {}
		}

		try {
			return this._connectSSE();
		} catch {}

		this.activeTransport = "rest";
	}

	/**
	 * Standard SSE.
	 * EventSource can only use GET, so the payload is appended to the URL.
	 */
	async _connectSSE() {
		this.activeTransport = "sse";

		const url = this._appendPayloadToUrl(this.options.endpoint, this.options.payload);

		// IMPORTANT: SSE with credentials enabled
		const es = new EventSource(url, { withCredentials: true });
		this._es = es;

		this._ensureCallback();

		// Standard message
		es.onmessage = (ev) => {
			let data = ev.data;
			try { data = JSON.parse(data); } catch {}
			this.onEventCallback("message", data);
		};

		// done / error
		["done", "error"].forEach(evt => {
			es.addEventListener(evt, (ev) => {
				let data = ev.data;
				try { data = JSON.parse(data); } catch {}
				this.onEventCallback(evt, data);
			});
		});

		// Custom event list
		if (Array.isArray(this.options.events)) {
			this.options.events.forEach(evt => {
				es.addEventListener(evt, (ev) => {
					let data = ev.data;
					try { data = JSON.parse(data); } catch {}
					this.onEventCallback(evt, data);
				});
			});
		}
	}

	/**
	 * POST-SSE mode:
	 * 1) POST to proxy with endpoint + payload
	 * 2) Proxy returns { ok, stream }
	 * 3) Connect EventSource to stream URL
	 */
	async _connectPostSSE() {
		this.activeTransport = "postsse";
		this._ensureCallback();

		try {
			const res = await fetch("/eventtransportpostsseproxy.php", {
				method: "POST",
				credentials: "include",		// important: send session cookie
				headers: {
					"Content-Type": "application/json"
				},
				body: JSON.stringify({
					endpoint: this.options.endpoint,
					payload: this.options.payload || {}
				})
			});

			let info;
			try {
				info = await res.json();
			} catch (e) {
				this.onEventCallback("error", { message: "Invalid postsse proxy response", error: e.toString() });
				return;
			}

			if (!info || !info.ok || !info.stream) {
				this.onEventCallback("error", info || { message: "postsse proxy failed" });
				return;
			}

			// IMPORTANT: SSE with credentials enabled
			const es = new EventSource(info.stream, { withCredentials: true });
			this._es = es;

			// Standard message
			es.onmessage = (ev) => {
				let data = ev.data;
				try { data = JSON.parse(data); } catch {}
				this.onEventCallback("message", data);
			};

			// done / error
			["done", "error"].forEach(evt => {
				es.addEventListener(evt, (ev) => {
					let data = ev.data;
					try { data = JSON.parse(data); } catch {}
					this.onEventCallback(evt, data);
				});
			});

			// Custom events
			if (Array.isArray(this.options.events)) {
				this.options.events.forEach(evt => {
					es.addEventListener(evt, (ev) => {
						let data = ev.data;
						try { data = JSON.parse(data); } catch {}
						this.onEventCallback(evt, data);
					});
				});
			}

		} catch (e) {
			this.onEventCallback("error", { message: "postsse connection error", error: e.toString() });
		}
	}

	async _connectWS() {
		return new Promise((resolve, reject) => {
			try {
				const ws = new WebSocket(this.options.endpoint);
				this._ws = ws;
				this.activeTransport = "ws";

				ws.onopen = () => resolve();

				ws.onmessage = (msg) => {
					let data = msg.data;
					try { data = JSON.parse(data); } catch {}
					this.onEventCallback("message", data);
				};

				ws.onerror = (err) => this.onEventCallback("error", err);
				ws.onclose = () => this.onEventCallback("close", null);

			} catch (e) {
				reject(e);
			}
		});
	}

	/**
	 * request() is used only by:
	 * - WS (send message)
	 * - REST (POST)
	 * SSE and POST-SSE ignore request(), because they already receive data via stream.
	 */
	async request(payload) {
		this._ensureCallback();

		// WS -> send directly
		if (this.activeTransport === "ws" && this._ws) {
			this._ws.send(JSON.stringify(payload));
			return;
		}

		// REST -> POST
		if (this.activeTransport === "rest") {
			const res = await fetch(this.options.endpoint, {
				method: "POST",
				credentials: "include",		// important: keep session stable
				headers: { "Content-Type": "application/json" },
				body: JSON.stringify(payload)
			});
			const json = await res.json();
			this.onEventCallback("message", json);
			return;
		}

		// SSE -> ignored
		// POST-SSE -> ignored
	}

	close() {
		if (this._es) this._es.close();
		if (this._ws) this._ws.close();
	}

	_appendPayloadToUrl(url, payload) {
		if (!url || !payload || typeof payload !== "object") {
			return url;
		}

		const params = [];

		Object.keys(payload).forEach(key => {
			const value = payload[key];

			if (value === null || typeof value === "undefined") {
				return;
			}

			if (typeof value === "string" || typeof value === "number" || typeof value === "boolean") {
				params.push(encodeURIComponent(key) + "=" + encodeURIComponent(String(value)));
				return;
			}

			params.push(encodeURIComponent(key) + "=" + encodeURIComponent(JSON.stringify(value)));
		});

		if (params.length === 0) {
			return url;
		}

		return url + (url.includes("?") ? "&" : "?") + params.join("&");
	}
}

window.EventTransportClient = EventTransportClient;
