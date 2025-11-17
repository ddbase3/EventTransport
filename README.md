# EventTransport

Unified event streaming for PHP backends and JavaScript frontends.

This library provides a transport-agnostic way to push events from a PHP application to a browser or any HTTP/WS client. It supports real streaming (SSE / WebSocket) and multiple polling fallbacks, but your application code only talks to a single `IEventStream` interface.

---

## Features

* **Single server-side API** via `IEventStream` (push events, finish stream, detect disconnects).
* **Five transport modes**: non-streaming JSON, short polling, long polling, Server-Sent Events (SSE), and WebSocket.
* **Automatic fallback** when a preferred transport is not available.
* **Plugin-based integration** with the BASE3 framework.
* **Simple JavaScript client** (`EventTransportClient`) with unified event handling API.
* Designed for **chat-like token streaming**, **progress updates**, or any incremental server output.

---

## Transport Modes

On the PHP side all transports implement `EventTransport\Api\IEventStream`:

```php
interface IEventStream {
    public function start(): void;
    public function push(string $event, array $data): void;
    public function sendComment(string $text): void;
    public function isDisconnected(): bool;
    public function finish(array $finalPayload): void;
}
```

Concrete implementations:

1. **NoStreamEventStream (`nostream`)**

   * Sends a single JSON response *once* at the end (in `finish()`).
   * All calls to `push()` are buffered in memory.
   * Safest mode for environments without streaming (e.g. heavy buffering).

2. **ShortPollingEventStream (`short`)**

   * Writes events into a queue file in `sys_get_temp_dir()`.
   * A separate "poll" HTTP endpoint reads and removes one event at a time using `pollNext()`.
   * Client performs regular short polling.

3. **LongPollingEventStream (`long`)**

   * Also uses a queue file.
   * A long-poll endpoint blocks until an event is available or a timeout is reached via `waitNext()`.
   * Reduces the number of HTTP round-trips compared to short polling.

4. **SseEventStream (`sse`)**

   * Uses native **Server-Sent Events** with `Content-Type: text/event-stream`.
   * Writes lines like `event: tick` and `data: {...}` directly to the response and flushes output.
   * `sendComment()` emits SSE comments (heartbeats).

5. **WebSocketEventStream (`ws`)**

   * Wraps a low-level WebSocket connection (e.g. Ratchet, Swoole, Workerman).
   * Sends JSON messages `{ "type": "eventName", "data": { ... } }`.
   * Requires an `IWebSocketConnectionResolver` implementation.

---

## Server-Side Architecture

### Core Interfaces

**`IEventStreamFactory`**

```php
interface IEventStreamFactory {
    public function createStream(string $serviceName, string $streamId): IEventStream;
}
```

**`IEventTransportConfig`**

```php
interface IEventTransportConfig {
    public function getDefaultMode(): string;          // nostream | short | long | sse | ws
    public function isAutoFallbackEnabled(): bool;

    /** @return string[] */
    public function getFallbackOrder(): array;
}
```

**`IWebSocketConnectionResolver`**

Resolves a WS connection object for a given service & stream or returns `null` if WS is not available.

```php
interface IWebSocketConnectionResolver {
    public function resolve(string $serviceName, string $streamId);
}
```

### EventStreamFactory

`EventTransport\Service\EventStreamFactory` is the central factory that chooses a concrete `IEventStream` implementation based on the configured mode and fallbacks.

```php
$stream = $eventStreamFactory->createStream('chatbot', $streamId);
```

Internally it:

* Reads the **default mode** from `IEventTransportConfig::getDefaultMode()`.
* Tries to create that transport (`nostream`, `short`, `long`, `sse`, or `ws`).
* If the mode is not available (e.g. no WS connection), it walks the **fallback order**.
* Ultimately falls back to `NoStreamEventStream` which always works.

### Base3 Plugin Integration

The plugin class `EventTransport\EventTransportPlugin` wires everything into the BASE3 DI container:

```php
class EventTransportPlugin implements IPlugin {

    public function __construct(private readonly IContainer $container) {}

    public static function getName(): string {
        return 'eventtransportplugin';
    }

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
```

The default config (`EventTransport\Config\EventTransportConfig`) chooses SSE with fallbacks:

```php
class EventTransportConfig implements IEventTransportConfig {
    public function getDefaultMode(): string { return 'sse'; }
    public function isAutoFallbackEnabled(): bool { return true; }
    public function getFallbackOrder(): array { return ['short', 'nostream']; }
}
```

You can replace this implementation with your own to change the global behaviour.

---

## Using EventTransport on the Server (PHP)

### 1. Typical SSE Streaming Endpoint

Example using BASE3's `IOutput` and the `IEventStreamFactory` to create an SSE stream:

```php
<?php declare(strict_types=1);

namespace ContourzTestWebsite\Content;

use Base3\Api\IOutput;
use EventTransport\Api\IEventStreamFactory;

class Sse implements IOutput {

    public function __construct(
        private readonly IEventStreamFactory $eventstreamfactory
    ) {}

    public static function getName(): string {
        return 'sse';
    }

    public function getOutput($out = 'html') {
        // Create SSE stream (serviceName, streamId)
        $stream = $this->eventstreamfactory->createStream(
            'ssestream-example',
            'sse-test'
        );

        // Start output early (sets headers & flushes for SSE)
        $stream->start();

        // Send 10 tick events
        for ($i = 1; $i <= 10; $i++) {

            $stream->push('tick', [
                'index' => $i,
                'time'  => microtime(true)
            ]);

            if ($stream->isDisconnected()) {
                break;
            }

            sleep(1);
        }

        // Final event (type = "done")
        $stream->finish([
            'status' => 'complete'
        ]);

        // IMPORTANT: return nothing else; SSE is already streamed directly.
        return '';
    }

    public function getHelp() {
        return "Help of Sse\n";
    }
}
```

If the runtime environment cannot do SSE, the factory may fall back to short polling or nostream depending on your config.

### 2. Non-Streaming JSON Endpoint (nostream)

If the configured mode is `nostream` (or all streaming modes fail), `NoStreamEventStream` will:

* Collect all `push()` events in an in-memory array.
* On `finish()`, send a single JSON response like:

```json
{
  "type": "done",
  "events": [
    { "type": "tick", "data": {"index": 1, "time": 123.45} },
    ...
  ],
  "data": {"status": "complete"}
}
```

You can rely on the same PHP code; only the client needs to interpret the JSON accordingly.

### 3. Short Polling Backend

Short polling uses a queue file per `(serviceName, streamId)` pair. A typical pattern:

1. One request **starts** the long-running operation and pushes events.
2. A separate *poll* endpoint makes use of `ShortPollingEventStream::pollNext()`.

Minimal poll endpoint example:

```php
<?php declare(strict_types=1);

use EventTransport\Stream\ShortPollingEventStream;

$service = $_GET['service'] ?? 'default';
$streamId = $_GET['stream'] ?? 'missing';

$stream = new ShortPollingEventStream();
$stream->init($service, $streamId);

header('Content-Type: application/json; charset=utf-8');

$next = $stream->pollNext();

if ($next === null) {
    echo json_encode(['type' => 'empty']);
} else {
    echo json_encode($next);
}
```

The producer side (your main business logic) uses `IEventStreamFactory` and `push()` as usual. The poll endpoint simply delivers queued events one-by-one.

### 4. Long Polling Backend

`LongPollingEventStream` works similarly, but offers `waitNext(int $timeoutSeconds)` which blocks until an event appears or the timeout is reached:

```php
$next = $stream->waitNext(20);  // blocks up to 20 seconds

if ($next === null) {
    echo json_encode(['type' => 'timeout']);
} else {
    echo json_encode($next);
}
```

Use this for lower overhead when the expected event rate is low but you still want near-real-time delivery.

### 5. WebSocket Backend

To use WebSockets:

1. Implement `IWebSocketConnectionResolver` to resolve a connection object (e.g. Ratchet connection) based on `serviceName` and `streamId`.
2. Register your resolver in the DI container instead of `NullWebSocketConnectionResolver`.
3. Set `getDefaultMode()` to `ws` or include `ws` in your fallback order.

The `WebSocketEventStream` will then send JSON messages for each `push()` call and a final `done` message in `finish()`.

---

## Using EventTransport on the Client (JavaScript)

The client side is a lightweight wrapper around REST, SSE, and WebSocket. It normalises all incoming messages so that your application can handle them through one callback.

### Including the client

```html
<script src="plugin/EventTransport/assets/eventtransportclient.js"></script>
```

This exposes `window.EventTransportClient`.

### Constructor Options

```javascript
const client = new EventTransportClient({
    endpoint: "sse.php",   // URL of your backend (HTTP or WS)
    transport: "auto",     // "auto" | "rest" | "sse" | "ws"
    events: ["tick"]       // custom SSE event types to listen for
});
```

Options:

* **endpoint**: URL or WebSocket URL.

  * For HTTP/S (REST or SSE): e.g. `"/sse.php"`.
  * For WebSocket: URL must start with `"ws"` or `"wss"`.
* **transport**:

  * `"sse"` – force Server-Sent Events.
  * `"ws"` – force WebSocket.
  * `"rest"` – no streaming; uses plain POST/JSON.
  * `"auto"` (default) – try WS if endpoint starts with `ws`, otherwise try SSE, fallback to REST.
* **events** (SSE only): array of custom SSE event names to register listeners for (e.g. `"tick"`, `"progress"`, `"tool.call"`).

### Connecting

```javascript
await client.connect((event, data) => {
    // event: string (e.g. "tick", "done", "message", "error", ...)
    // data:  any (parsed JSON if possible)
});
```

The callback receives *all* events, including built-ins:

* `"message"` – default SSE/WS messages.
* `"done"` – final event from the server-side `finish()`.
* `"error"` – connection error.
* Any custom events you configured in `events` (e.g. `"tick"`, `"progress"`).

### Sending requests (REST / WS)

```javascript
await client.request({
    action: "start",
    payload: { prompt: "Hello" }
});
```

* For **WebSocket**, the payload is sent via `ws.send(JSON.stringify(payload))`.
* For **REST**, the client sends a POST request with JSON body and calls your callback once with the JSON response under the `"message"` event.
* For **SSE**, `request()` is usually *not* used – the stream is established by calling `connect()` and the server starts pushing events immediately.

### Closing the connection

```javascript
client.close();
```

This closes the underlying `EventSource` (SSE) or `WebSocket` connection.

---

## Complete Example: Browser Client for SSE

A minimal HTML page using `EventTransportClient` with a server endpoint similar to the `Sse` example above:

```html
<!DOCTYPE html>
<html>
<head>
    <meta charset="utf-8">
    <title>EventTransport Test</title>
    <style>
        body { font-family: sans-serif; padding:20px; }
        #log { white-space:pre; background:#f5f5f5; padding:10px; border:1px solid #ccc; height:300px; overflow-y:auto; }
        .tick { color:#0077cc; }
        .done { color:#990000; }
    </style>

    <script src="plugin/EventTransport/assets/eventtransportclient.js"></script>
</head>
<body>

<h1>EventTransport Streaming Test</h1>

<button id="startBtn">Start Stream</button>

<h2>Log</h2>
<div id="log"></div>

<script>
function log(msg, cls = "") {
    const el = document.createElement("div");
    if (cls) el.className = cls;
    el.textContent = msg;
    document.getElementById("log").appendChild(el);
    document.getElementById("log").scrollTop = 999999;
}

// Start streaming when button is clicked
document.getElementById("startBtn").addEventListener("click", async () => {
    log("Opening SSE stream using EventTransportClient ...");

    const client = new EventTransportClient({
        endpoint: "sse.php",   // or any other endpoint resolving to Sse IOutput
        transport: "sse",      // explicitly use SSE
        events: ["tick"]       // register custom SSE event names you want to handle
    });

    await client.connect((event, data) => {
        // All events arrive here: "tick", "done", "message", "error", ...
        switch (event) {
            case "tick":
                if (typeof data === "string") {
                    try { data = JSON.parse(data); } catch {}
                }
                log("[tick] index=" + data.index + " time=" + data.time, "tick");
                break;

            case "done":
                if (typeof data === "string") {
                    try { data = JSON.parse(data); } catch {}
                }
                log("DONE received! " + JSON.stringify(data), "done");
                client.close();
                break;

            case "message":
                log("MESSAGE: " + JSON.stringify(data));
                break;

            case "error":
                log("STREAM ERROR: " + data, "done");
                break;

            default:
                // All other custom events
                log("EVENT [" + event + "]: " + JSON.stringify(data));
        }
    });
});
</script>

</body>
</html>
```

---

## End-to-End Workflow

1. **Choose a service name and stream ID** on the server (e.g. `"chatbot"` and `"uuid-123"`).
2. **Create a stream** via `IEventStreamFactory::createStream($serviceName, $streamId)`.
3. **Start streaming** with `start()`, then call `push($eventName, $data)` in a loop or as new data arrives.
4. Regularly check `isDisconnected()` to avoid unnecessary work if the client has left.
5. When the task is complete, call `finish($finalPayload)` – this emits the final `done` event.
6. On the client, **create an `EventTransportClient`** with a matching endpoint and call `connect()`.
7. Handle all incoming events in the callback and update your UI.

The same server-side code can run in any configured mode. Changing the transport (SSE ↔ polling ↔ WebSocket) is a configuration concern, not an application code change.

---

## Tips & Best Practices

* **Always treat `event` as a string and `data` as arbitrary JSON** in the client callback.
* For SSE, avoid any extra whitespace or output after `finish()` – the stream should contain only SSE lines.
* For polling modes, make sure your queue files are stored in a directory writable by PHP (default: `sys_get_temp_dir()`).
* Implement your own `IEventTransportConfig` to control the global strategy (e.g. prefer `ws`, then `sse`, then `short`).
* For WebSockets, keep connection lifecycle under the control of your WS server. `WebSocketEventStream` intentionally does **not** force-close the connection.

---

## License

EventTransport is licensed under the GPL 3.0 license.

