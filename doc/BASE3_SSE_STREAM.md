# LLM Streaming via SSE — Updated Integration Guide (EventTransport Edition)

Diese Version aktualisiert das alte Dokument vollständig und passt es an dein aktuelles, echtes System an:

**Apache2 + PHP‑FPM + MissionBay + EventTransport + StreamingAiAssistantNode + SseEventStream**

Kein cURL‑Beispiel mehr, kein veralteter Minimal-SSE-Code.
Nur das, was du wirklich einsetzt.

---

# 1. Architektur — Überblick

```
Browser
  └── EventTransportClient.js
        └── (auto | rest | ws | sse | postsse)
             └── POST-SSE Proxy (EventTransportPostSse)
                     └── MissionBay Flow
                           └── StreamingAiAssistantNode
                                 └── SseEventStream
                                       └── Apache SSE
```

Du hast **zwei Streaming-Wege**:

### 1.1 Direkt-SSE (GET)

Der Browser nutzt `EventSource(url)` → der PHP-Endpoint sendet echtes SSE.

### 1.2 POST‑SSE (Proxy)

Browser macht POST → Proxy speichert Payload in Session → Proxy ruft Server per POST → Server streamt → Proxy streamt weiter → Browser bekommt echtes SSE.

Diese Variante ist nötig, wenn:

* du POST-Daten (Prompt, Konfig usw.) mit SSE kombinierst
* EventSource kein POST kann

---

# 2. Kernkomponente: `SseEventStream`

Dies ist dein echtes SSE-Modul.
Kein Buffering, keine Kompression, volle Kontrolle.

Wichtigste Merkmale:

* deaktiviert Output‑Buffering zuverlässig
* setzt `Content-Type: text/event-stream`
* setzt `X-Accel-Buffering: no`
* nutzt sofortiges Flushen
* erkennt Verbindungsabbruch zuverlässig

API:

```php
$stream->start();                   // Headers + Flush
$stream->push('event', ['key' => 'value']);
$stream->sendComment('ping');       // heartbeat
$stream->isDisconnected();          // true/false
$stream->finish([...]);             // final event + flush
```

---

# 3. MissionBay: `StreamingAiAssistantNode`

Der Node führt einen zweiphasigen Ablauf aus:

1. **Tool-Calling (non-stream)**
   Standard Chat Completion ohne Streaming
   → speichert User-Messages sofort in Memory
   → speichert Tool-Antworten ebenfalls

2. **Finale Antwort (stream)**
   → OpenAI streamt Tokens
   → Node ruft für jedes Token `$stream->push('token', [...])`

Nach Abschluss:

* gesamte finale Antwort wird erneut als Message in Memory gespeichert
* Memory ist vollständig kompatibel zur non-streaming Variante

---

# 4. POST-SSE Proxy: `EventTransportPostSse`

Diese Klasse ermöglicht POST + SSE:

Ablauf:

1. Browser POST → `/eventtransportpostsseproxy.php`
2. Proxy speichert Payload per Session unter zufälliger ID
3. Proxy antwortet JSON: `{ ok: true, stream: "/eventtransportpostsse?id=XYZ" }`
4. Browser startet `EventSource(streamUrl)`
5. Proxy öffnet SSE‑Stream und ruft **intern** den Ziel-Endpoint erneut auf (diesmal POST)
6. Proxy schreibt Upstream‑Chunks 1:1 in den SSE‑Body

**Wichtig:**
Die Session muss stabil sein (PHP-FPM darf nicht sterben, Cookie muss bleiben).

---

# 5. Browser: `EventTransportClient.js`

Dieses Script ist deine universelle Transport-Schicht.

Unterstützt:

* SSE (GET)
* POST-SSE (Proxy)
* WebSocket
* REST (Fallback)
* Auto-Detection

Die wichtigen Punkte für SSE:

* Standard-SSE nutzt GET → Payload in Querystring
* POST-SSE nutzt den Proxy → Payload bleibt im POST
* Alle Streams liefern Events: `message`, `done`, `error`, + custom

Integration:

```js
const client = new EventTransportClient({
	endpoint: "/chatagentstream.php",
	transport: "postsse",          // oder „sse“
	payload: { prompt: "Hi" },
	events: ["msgid", "token", "meta"]
});

await client.connect((event, data) => {
	console.log(event, data);
});
```

---

# 6. Apache 2 — Anforderungen für SSE

Für funktionierendes Streaming:

* HTTP/2 ist ok (du nutzt es)
* Keine Kompression auf SSE
* Kein Proxy-Buffering
* Kein Output-Buffer mehrstufig

Min=Konfiguration für SSE:

```
Header always unset Content-Encoding
Header always set X-Accel-Buffering "no"
SetEnvIfNoCase Content-Type "^text/event-stream" no-gzip
SetEnvIfNoCase Content-Type "^text/event-stream" no-brotli
```

Wichtig:
Diese Regeln **dürfen NIEMALS andere Seiten beeinträchtigen**.
Daher: niemals global anwenden. Nur gezielt für SSE-PHP.

---

# 7. Debug-Hinweise

### 7.1 Wenn die Gesamtseite hängt

→ PHP-Prozess wird durch lange SSE offen gehalten
→ **Lösung:** SSE‑Endpoints isolieren (eigenes PHP-FPM Pool optional)

### 7.2 Wenn Memory nicht funktioniert

→ Node-ID im Flow ist nicht identisch
→ Beispiel: "assistant" ≠ "streamingaiassistantnode"

### 7.3 Wenn SSE abbricht

→ Browser-Sicherheitsrichtlinie: EventSource kann kein POST
→ Verwende POST‑SSE Proxy

### 7.4 Wenn Proxy ein leeres SSE ausgibt

→ Session wurde beim Proxy-Zweitaufruf nicht gefunden
→ Cookies prüfen (Secure, SameSite, Domain)

---

# 8. End-to-End Flow (konkret)

1. Browser → `EventTransportClient.connect()`
2. Client → POST an Proxy
3. Proxy → speichert Payload in Session
4. Proxy → liefert SSE-URL zurück
5. Browser → startet `EventSource(sseUrl)`
6. Proxy → öffnet SSE + ruft Endpunkt intern per POST auf
7. MissionBay → StreamingAiAssistantNode erstellt SSE‑Tokens
8. SseEventStream → sendet Tokens
9. Browser erhält: `msgid`, `token`, `meta`, `done`

---

# 9. Ergebnis

Mit diesem Aufbau erreichst du:

* absolut zuverlässiges SSE unter Apache2 + PHP-FPM
* funktionierenden POST+SSE Flow
* kompatible Memory-Verwaltung
* echte LLM Token-Streams

Das Dokument ist ein vollständiges, aktuelles Architektur- und Entwickler-Manual für dein System.
