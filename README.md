# EventTransport System (PHP + JavaScript)

## Overview

This document describes the complete EventTransport architecture. It includes all five transport modes, PHP server components, JavaScript client components, configuration options, and integration patterns.

---

## Goals

* Provide a unified event streaming system with multiple transport modes.
* Support environments with or without streaming capabilities.
* Allow multiple concurrent services to stream data independently.
* Decouple services from transport choice using a central configuration.
* Allow plug-and-play behaviour in BASE3 environments via a dedicated plugin.

---

## Supported Transport Modes

The EventTransport system supports five transport modes:

### 1. NoStream

* Single HTTP request.
* Returns full JSON response.
* Best compatibility mode (shared hosting, no streaming support).

### 2. Short Polling

* Client periodically polls an endpoint.
* Server emits one message per request.
* Very reliable.

### 3. Long Polling

* Client sends blocking GET requests.
* Server responds when an event is available.
* Less network traffic than short polling.

### 4. Server-Sent Events (SSE)

* Real-time text/event-stream connection.
* Requires server support.
* Not available in many shared hosting environments.

### 5. WebSocket

* Full-duplex stream.
* Requires a WebSocket backend (Ratchet, Swoole, Node.js, etc.).

---

## Architecture Summary

The system consists of:

### PHP

* **IEventStream**: Interface implemented by all server-side stream types.
* **EventStreamFactory**: Chooses a stream implementation based on configuration.
* **EventTransportPlugin**: Registers core services in the BASE3 DI container.
* **IWebSocketConnectionResolver**: Optional adapter for WS connections.
* **EventTransportConfig**: Centralised configuration.

### JavaScript

* **EventTransportConfig**: Defines client-side settings.
* **AbstractTransport**: Base class for all transports.
* **Transport classes**: NoStream, ShortPolling, LongPolling, SSE, WebSocket.
* **TransportResolver**: Chooses best transport given the environment.

---

## Multiple Concurrent Streams

Each transport instance is identified by:

* `serviceName`
* `streamId`

This allows multiple independent streams to run side by side. Example:

* Chat widget streaming tokens.
* Another widget streaming file processing progress.

Both receive events independently and do not interfere with each other.

---

## Server-Side: EventStreamFactory

The factory selects the appropriate transport mode:

```
nostream → safest
short → fallback
long → efficient polling
sse → real-time
ws → full-duplex
```

If the preferred mode is unavailable, fallback modes are used automatically.

---

## Server-Side: EventTransportPlugin

This plugin integrates the entire EventTransport layer into BASE3.

It registers:

* EventTransportConfig
* NullWebSocketConnectionResolver (if no WS server is available)
* EventStreamFactory

This creates a central transport choice shared by all plugins and services.

---

## JavaScript: EventTransportConfig

Example configuration:

```javascript
const cfg = new EventTransportConfig({
	mode: "nostream",
	fallbackModes: ["short", "long"],
	baseHttpUrl: "/mcp/stream",
	wsUrl: null
});
```

---

## JavaScript: TransportResolver

This creates a transport instance based on the configuration and runtime environment.

Example:

```javascript
const channel = TransportResolver.createChannel(cfg, "chatbot", "stream123");

channel.onMessage(msg => console.log("EVENT", msg));
channel.connect({ prompt: "Hello" });
```

---

## Transport Behaviour Breakdown

### NoStream

* One request, one full response.
* Good for environments with aggressive buffering.

### Short Polling

* Regular intervals (e.g. 120ms).
* Server writes events into a queue.
* Best universal fallback.

### Long Polling

* Client waits until server emits an event.
* Low network overhead.

### SSE

* Server sends streaming events.
* Native browser support.
* Requires server ability to bypass buffers.

### WebSocket

* Bi-directional.
* Lowest latency.
* Requires an external WS server.

---

## Complete Example: Chat Streaming

### PHP

```php
$stream = $factory->createStream("chatbot", $streamId);
$stream->start();
$stream->push(["type" => "token", "value" => "Hello "]);
$stream->push(["type" => "token", "value" => "World"]);
$stream->finish(["message" => "done"]);
```

### JavaScript

```javascript
const channel = TransportResolver.createChannel(cfg, "chatbot", streamId);
channel.onMessage(chunk => {
	ui.appendChunk(chunk);
});
channel.connect({ prompt: "hi" });
```

---

## Short-Poll Backend Protocol

Short polling requires two endpoints:

### 1. POST /short?service=X&stream=Y

Initial request to start server-side stream.

### 2. GET /short?service=X&stream=Y

Returns one message per request:

```json
{ "type": "token", "value": "Hello" }
```

When the stream is done:

```json
{ "type": "done" }
```

---

## Long-Poll Backend Protocol

Long polling uses the same endpoints but the GET blocks until an event arrives.

---

## SSE Backend Protocol

Server must emit:

```
data: {"type":"token","value":"Hi"}

data: {"type":"done"}

```

---

## WebSocket Backend Protocol

Client and server exchange JSON messages:

```json
{
	"type": "token",
	"value": "Hello"
}
```

If the server supports sending structured events, the JavaScript transport handles it automatically.

---

## Fallback Logic

If SSE or WS cannot be used (e.g. no support or blocked), the resolver falls back:

```
sse → long → short → nostream
```

The fallback order is configurable.

---

## Recommendations

* Use **nostream** on shared hosting.
* Use **short polling** for stable pseudo-streaming.
* Use **long polling** for efficient event delivery.
* Use **SSE** in controlled environments.
* Use **WebSocket** for full interactive systems.

---

## Summary

EventTransport provides a robust, flexible, environment-aware method for real-time and pseudo-real-time communication.

It solves:

* Environments with no streaming
* Aggressive buffering
* Need for multiple parallel services
* Automatic fallback handling
* Clear separation between transport and business logic

The system is designed to scale from shared hosting to fully asynchronous microservice setups.

---

## Next Steps

You can extend the system by adding:

* A WebSocket backend
* Redis-backed queues for high load
* Global logging of stream events
* Integration with MissionBay flows

If you need these additions, they can be provided as follow-up modules.

