# Apache2 Configuration for Server-Sent Events (SSE)

Diese Anleitung beschreibt **präzise, schlank und ausschließlich die Einstellungen**, die in der Praxis wirklich erforderlich sind, damit **PHP-basierte SSE-Streams über Apache + PHP-FPM stabil funktionieren**.

Sie bildet exakt das Setup ab, das in deinem Live-System fehlerfrei läuft.

---

# 1. Ziel

SSE funktioniert nur, wenn **KEINE Form von Output-Buffering** aktiv ist.

Damit ein Event sofort beim Browser ankommt müssen:

* keine Proxy-Puffer greifen
* keine Apache-Filter komprimieren
* kein PHP-/FPM-Puffer aktiv sein
* der SSE-PHP-Code korrekt Flushen

Diese Datei stellt das Minimum sicher, damit Apache und PHP-FPM nicht blockieren.

---

# 2. Voraussetzungen

**Diese Apache-Module müssen aktiviert sein:**

```bash
a2enmod proxy proxy_fcgi headers
```

Optional (für normale HTML-Seiten):

```bash
a2enmod deflate brotli
```

Restart:

```bash
systemctl reload apache2
```

---

# 3. PHP-FPM / php.ini Einstellungen

Unter:

```
/etc/php/8.3/fpm/php.ini
```

Folgende Werte müssen gesetzt sein:

```ini
output_buffering = Off
zlib.output_compression = Off
```

Optional, aber hilfreich:

```ini
implicit_flush = On
```

Restart:

```bash
systemctl restart php8.3-fpm
```

---

# 4. Apache: Kompression für SSE sauber deaktivieren

**WICHTIG:** Brotli/Deflate zerstören SSE-Events, wenn sie komprimiert werden.
Deshalb muss Kompression nur für **SSE-Antworten** deaktiviert werden.

Datei:

```
/etc/apache2/conf-enabled/sse-global.conf
```

Inhalt:

```apache
<IfModule mod_setenvif.c>
    # SSE erkennen
    SetEnvIfNoCase Content-Type ".*text/event-stream.*" IS_SSE=1
</IfModule>

<IfModule mod_headers.c>
    # Kompression ausschalten
    Header always unset Content-Encoding env=IS_SSE
    Header always set   X-Accel-Buffering "no" env=IS_SSE
    Header always set   Cache-Control "no-cache" env=IS_SSE
</IfModule>

<IfModule mod_deflate.c>
    SetEnvIfNoCase Content-Type "^text/event-stream" no-gzip=1
</IfModule>

<IfModule mod_brotli.c>
    SetEnvIfNoCase Content-Type "^text/event-stream" no-brotli=1
</IfModule>
```

Diese Datei ist getestet und stabil.
Sie beeinflusst reguläre Seiten **nicht**.

Reload:

```bash
systemctl reload apache2
```

---

# 5. Beispiel VHost (Ausschnitt)

Nur der **relevante Teil**:

```apache
<FilesMatch "\.php$">
    SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
</FilesMatch>
```

Nichts weiter ist im VHost nötig.

---

# 6. Minimal funktionierender SSE-PHP-Code

```php
<?php
while (ob_get_level() > 0) {
    ob_end_clean();
}

header("Content-Type: text/event-stream; charset=UTF-8");
header("Cache-Control: no-cache");
header("X-Accel-Buffering: no");
header("Connection: keep-alive");

flush();

for ($i = 1; $i <= 5; $i++) {
    echo "event: tick\n";
    echo "data: {\"i\": $i}\n\n";
    flush();
    if (connection_aborted()) break;
    sleep(1);
}

echo "event: done\n";
echo "data: {\"status\": \"complete\"}\n\n";
flush();
```

Dieser Code ist vollständig kompatibel mit dem Apache-Setup.

---

# 7. Troubleshooting (Kurz & Präzise)

### Problem: Ereignisse kommen gesammelt statt einzeln

**Ursache:** Kompression aktiv
**Fix:** Prüfe `sse-global.conf`

### Problem: Seite lädt nicht mehr während SSE läuft

**Ursache:** Falsche Apache-Konfiguration oder defekte Header
**Fix:** Entferne riskante Direktiven wie `setifempty` → wir haben das bereits korrigiert.

### Problem: SSE bricht nach wenigen Sekunden ab

**Ursache:** Browser idle timeout
**Fix:** Heartbeats senden:

```php
echo ": ping\n\n";
flush();
```

---

# 8. Zusammenfassung (die ganze Wahrheit in 5 Zeilen)

SSE funktioniert zuverlässig, wenn:

1. **php.ini:** `output_buffering=Off`, `zlib.output_compression=Off`
2. **Apache:** Kein Brotli/Deflate für `text/event-stream`
3. **Header:** `X-Accel-Buffering: no`, `Content-Encoding: unset`
4. **PHP:** `ob_end_clean(); flush();`
5. **Event-Loop:** regelmäßig flushen

Mehr ist **nicht** nötig.

---

Dieses Dokument ist die finale Version und bildet exakt das jetzt funktionierende System ab.

