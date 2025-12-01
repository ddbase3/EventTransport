# SSE + LLM Streaming Integration Guide (Updated)

Diese Anleitung beschreibt **vollständig und aktuell**, wie man ein LLM (z. B. OpenAI) per **Streaming-API** abruft und dessen Output **live per Server-Sent Events (SSE)** an den Browser weitergibt.

Der Ablauf:

**LLM → PHP (cURL Streaming) → SSE → Browser**

Die Anleitung funktioniert auf jedem korrekt konfigurierten Apache2-Server, sofern SSE-Streaming aktiviert ist.

---

# 🧩 Übersicht

1. **SseStream Klasse** – korrektes SSE-Handling + Header + Flush
2. **AI-Stream PHP Endpoint** – ruft OpenAI streamend ab, leitet Tokens weiter
3. **Browser-Code** – verarbeitet die Events live

---

# 1. PHP: `SseStream` Klasse (NEUE VERSION)

Diese Version funktioniert zuverlässig mit Apache2 + PHP-FPM und enthält **alle notwendigen Header** sowie korrektes Buffer-Handling.

```php
<?php
class SseStream {
	public function __construct() {
		// Output buffering komplett deaktivieren
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		header('Content-Type: text/event-stream; charset=UTF-8');
		header('Cache-Control: no-cache');
		header('X-Accel-Buffering: no');
		header('Connection: keep-alive');

		@ini_set('implicit_flush', '1');
		@ini_set('output_buffering', 'off');
		ob_implicit_flush(true);

		echo "\n";
		flush();
	}

	public function send(string $event, string $data): void {
		echo "event: {$event}\n";
		echo "data: {$data}\n\n";
		flush();
	}

	public function finish(): void {
		$this->send("done", json_encode(["status" => "complete"]));
		flush();
		exit;
	}
}
```

---

# 2. PHP: AI-Stream Endpoint (`ai-stream.php`)

Dieser Endpoint ruft OpenAI per Streaming ab und leitet die empfangenen Token per SSE an die Clients weiter.

**Diese Version entspricht dem aktuellen, funktionierenden Stand!**

```php
<?php
require_once __DIR__ . '/SseStream.php';

$apiKey = 'DEIN_OPENAI_KEY';

$stream = new SseStream();

$ch = curl_init('https://api.openai.com/v1/chat/completions');

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
	CURLOPT_WRITEFUNCTION => function($ch, $data) use ($stream) {

		foreach (explode("\n", trim($data)) as $line) {
			if (strpos($line, 'data: ') === 0) {
				$json = trim(substr($line, 6));

				if ($json === '[DONE]') {
					$stream->finish();
					return strlen($data);
				}

				$decoded = json_decode($json, true);
				if ($decoded) {
					$stream->send('chunk', json_encode($decoded));
				}
			}
		}

		return strlen($data);
	}
]);

curl_exec($ch);
curl_close($ch);
```

---

# 3. Browser: EventSource Client

Diese Version funktioniert zuverlässig mit Chrome, Firefox, Safari.

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
	const el = document.getElementById('log');
	el.textContent += msg + "\n";
	el.scrollTop = el.scrollHeight;
}

function startStream() {
	const es = new EventSource('ai-stream.php');

	es.addEventListener('chunk', ev => {
		log(ev.data);
	});

	es.addEventListener('done', ev => {
		log('STREAM ENDE: ' + ev.data);
		es.close();
	});

	es.onerror = err => {
		log('Verbindung verloren.');
		es.close();
	};
}
</script>

</body>
</html>
```

---

# 🧪 Ablauf des Streams

```
OpenAI → cURL Streaming → PHP (ai-stream.php) → SseStream → Browser (EventSource)
```

✔ **Keine Pufferung**
✔ **Token erscheinen sofort**
✔ **LLM wirkt 100 % live**
✔ **Browser-kompatibel**

---

# Hinweis

Diese Anleitung ist jetzt exakt an dein funktionierendes Setup angepasst. Die Codes sind kompatibel mit Apache2 + PHP-FPM + deiner aktuellen Server-Konfiguration.

