# SSE + LLM Streaming Integration Guide

Diese Anleitung beschreibt **vollständig**, wie man ein LLM (z. B. OpenAI) per **cURL-Streaming** abruft und dessen Output **live per Server-Sent Events (SSE)** an den Browser weiterreicht.

Damit entsteht ein sauberer Echtzeit-Stream: **LLM → Server → SSE → Browser**.

Die Anleitung funktioniert **auf jedem korrekt konfigurierten Apache2-Server**, der für SSE vorbereitet ist (siehe vorherige README).

---

# 🧩 Übersicht

1. **SseStream Klasse** – abstrahiert die gesamte Kopfzeilen- & Flush-Logik
2. **AI-Stream PHP Endpoint** – empfängt LLM-Chunks per cURL und sendet sie als SSE weiter
3. **Browser-Code (EventSource)** – empfängt Tokens live im Browser

---

# 1. PHP: `SseStream` Klasse

Diese Klasse kapselt **alle notwendigen SSE-Header**, deaktiviert Buffering und bietet eine einfache API.

> **Hinweis:** Diese Klasse ist selbstständig einsetzbar und unabhängig von BASE3.

```php
<?php
class SseStream {
    public function __construct() {
        // Alle Levels beenden
        while (ob_get_level() > 0) {
            ob_end_clean();
        }

        // Header
        header('Content-Type: text/event-stream');
        header('Cache-Control: no-cache');
        header('X-Accel-Buffering: no');
        header('Connection: keep-alive');

        // PHP
        ini_set('implicit_flush', '1');
        ini_set('output_buffering', 'off');
        ob_implicit_flush(1);
    }

    /**
     * Sendet ein SSE-Event an den Client.
     */
    public function send(string $event, string $data): void {
        echo "event: {$event}\n";
        echo "data: {$data}\n\n";
        flush();
    }

    /**
     * Stream beenden
     */
    public function finish(): void {
        $this->send("done", json_encode(["status" => "complete"]));
        flush();
        exit;
    }
}
```

---

# 2. PHP: AI-Stream Endpoint (`ai-stream.php`)

Dieser Endpoint ruft OpenAI im **Streaming-Modus** auf und leitet die Chunks 1:1 per SSE weiter.

### 🔧 Vollständiges Beispiel

```php
<?php
require_once __DIR__ . "/SseStream.php";

$apiKey = 'DEIN_OPENAI_KEY';

$stream = new SseStream();

// CURL Request vorbereiten
$ch = curl_init("https://api.openai.com/v1/chat/completions");

$postData = [
    "model" => "gpt-4o-mini",
    "stream" => true,
    "messages" => [
        ["role" => "user", "content" => "Sag mir etwas über SSE."]
    ]
];

curl_setopt_array($ch, [
    CURLOPT_POST => true,
    CURLOPT_HTTPHEADER => [
        "Authorization: Bearer {$apiKey}",
        "Content-Type: application/json",
    ],
    CURLOPT_POSTFIELDS => json_encode($postData),
    CURLOPT_WRITEFUNCTION => function ($ch, $data) use ($stream) {

        // OpenAI sendet mehrere Zeilen
        foreach (explode("\n", trim($data)) as $line) {
            if (strpos($line, "data: ") === 0) {
                $json = trim(substr($line, 6));

                if ($json === "[DONE]") {
                    $stream->finish();
                    return strlen($data);
                }

                $decoded = json_decode($json, true);
                if ($decoded) {
                    $stream->send("chunk", json_encode($decoded));
                }
            }
        }

        return strlen($data);
    },
]);

curl_exec($ch);
curl_close($ch);
```

---

# 3. Browser: EventSource Client

Der Browser empfängt die Daten vollständig live.

```html
<!DOCTYPE html>
<html>
<head>
<meta charset="utf-8">
<title>AI Streaming Test</title>
<style>
#log { white-space: pre; background:#eee; padding:10px; height:300px; overflow-y:auto; }
</style>
</head>
<body>

<h1>AI Live Stream</h1>
<button onclick="startStream()">Start</button>
<pre id="log"></pre>

<script>
function log(msg) {
    const el = document.getElementById("log");
    el.textContent += msg + "\n";
    el.scrollTop = 999999;
}

function startStream() {
    const es = new EventSource("ai-stream.php");

    es.addEventListener("chunk", ev => {
        const data = JSON.parse(ev.data);
        log(JSON.stringify(data));
    });

    es.addEventListener("done", ev => {
        log("STREAM ENDE: " + ev.data);
        es.close();
    });

    es.onerror = err => {
        log("Verbindung verloren.");
        es.close();
    };
}
</script>

</body>
</html>
```

---

# 🧪 Wie der Flow funktioniert

```
OpenAI → cURL (Streaming) → PHP (ai-stream.php) → SseStream → Browser
```

### ✔ Keine Zwischenpufferung

### ✔ Token erscheinen sofort

### ✔ Browser zeigt echtes Live-LLM-Verhalten

### ✔ Funktioniert auf jedem Apache2 Server (mit SSE-Config)

