// Updated EventTransportClient.js
// Supports: REST, SSE, WebSocket
// Uses user-provided event list for SSE

class EventTransportClient {
    constructor(options = {}) {
        this.options = Object.assign({
            endpoint: null,
            transport: "auto", // auto | rest | sse | ws
            events: []          // custom SSE events
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

        // Explicit choice
        if (this.options.transport === "sse") {
            return this._connectSSE();
        }
        if (this.options.transport === "ws") {
            return this._connectWS();
        }
        if (this.options.transport === "rest") {
            this.activeTransport = "rest";
            return;
        }

        // AUTO DETECTION LOGIC
        // Try WebSocket first but only if WS URL is clearly provided
        if (typeof this.options.endpoint === "string" && this.options.endpoint.startsWith("ws")) {
            try {
                return await this._connectWS();
            } catch {}
        }

        // Otherwise assume SSE
        try {
            return this._connectSSE();
        } catch {}

        // Fallback REST
        this.activeTransport = "rest";
    }

    async _connectSSE() {
        this.activeTransport = "sse";

        const es = new EventSource(this.options.endpoint);
        this._es = es;

        this._ensureCallback();

        // STANDARD MESSAGE
        es.onmessage = (ev) => {
            let data = ev.data;
            try { data = JSON.parse(data); } catch {}
            this.onEventCallback("message", data);
        };

        // DONE + ERROR standard
        ["done", "error"].forEach(evt => {
            es.addEventListener(evt, (ev) => {
                let data = ev.data;
                try { data = JSON.parse(data); } catch {}
                this.onEventCallback(evt, data);
            });
        });

        // USER EVENT LIST
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

    // Send request (REST or WS)
    async request(payload, onChunk = null) {
        this._ensureCallback();

        if (this.activeTransport === "ws" && this._ws) {
            this._ws.send(JSON.stringify(payload));
            return;
        }

        // REST: POST
        if (this.activeTransport === "rest") {
            const res = await fetch(this.options.endpoint, {
                method: "POST",
                headers: { "Content-Type": "application/json" },
                body: JSON.stringify(payload)
            });
            const json = await res.json();
            this.onEventCallback("message", json);
        }
    }

    close() {
        if (this._es) this._es.close();
        if (this._ws) this._ws.close();
    }
}

window.EventTransportClient = EventTransportClient;
