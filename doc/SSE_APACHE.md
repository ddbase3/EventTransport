# Apache2 Server-Sent Events (SSE) – Vollständige Installations- und Konfigurationsanleitung

Diese Anleitung beschreibt **vollständig, lückenlos und produktionstauglich**, wie ein Apache2-Webserver so eingerichtet wird, dass **Server-Sent Events (SSE)** zuverlässig und in Echtzeit funktionieren.

Die Anleitung ist so geschrieben, dass du damit **jederzeit einen neuen Server (Ubuntu + Apache2 + PHP-FPM)** einrichten kannst, ohne weiter nachdenken zu müssen.

Sie deckt ALLE kritischen Punkte ab:

* Apache MPM-Modus
* Modul-Konfiguration (proxy, proxy_fcgi, headers, http2, deflate/brotli, etc.)
* FPM-Einstellungen
* Buffering verhindern (wichtigster Teil!)
* Sicherheit / ModSecurity
* Kompression korrekt konfigurieren
* VHost-Konfiguration (Port 80 + 443)
* PHP-Einstellungen
* Typische Fehler & deren Behebung
* Test-Skript

Ziel: **SSE funktioniert sofort, out of the box, bei jedem neuen Server.**

---

# 1. Grundlagen: Was SSE braucht

SSE (Server-Sent Events) setzt voraus:

1. **Echtzeit-Flushing** → nichts darf gepuffert werden
2. **Keine Kompression für SSE-Antworten** (Brotli/Deflate zerstören Event-Frames)
3. **Keine FPM-Ausgabe-Pufferung**
4. **Apache muss die Daten sofort weiterleiten**
5. **HTTP/1.1 oder HTTP/2**
6. **Kein Reverse-Proxy-Buffern** (z. B. Nginx Buffering OFF) – hier irrelevant
7. **ModSecurity darf SSE nicht blockieren**

Die Config muss also klarstellen:

* Apache darf NICHT puffern
* PHP-FPM darf NICHT puffern
* Output darf NICHT komprimiert werden
* SSE ist ein Sonderfall → global konfiguriert, ohne dass normale Seiten beeinflusst werden

---

# 2. Benötigte Apache-Module installieren & aktivieren

Folgende Module werden benötigt, um SSE über PHP-FPM zu betreiben:

```bash
a2enmod proxy
auselect2enmod proxy_fcgi
a2enmod headers
a2enmod http2
```

Optional (für Kompression der normalen Seiten):

```bash
a2enmod deflate
# oder / und
a2enmod brotli
```

Nicht zwingend, aber üblich:

```bash
a2enmod rewrite
```

**ModSecurity** ist optional – bei Konflikten siehe Kapitel 10.

Danach:

```bash
systemctl restart apache2
```

---

# 3. Apache MPM überprüfen

SSE funktioniert **mit jedem MPM**, aber am besten mit:

✔ **event**
❌ prefork → ineffizient, langsamer, aber erlaubt

Check:

```bash
apache2ctl -V | grep MPM
```

Wenn du `prefork` siehst und umstellen willst:

```bash
apt install apache2-bin
apt install libapache2-mpm-event
```

Dann:

```bash
a2dismod mpm_prefork
a2enmod mpm_event
systemctl restart apache2
```

---

# 4. PHP-FPM richtig konfigurieren

PHP-FPM selbst **puffert NIE**, aber es hat indirecte Einstellungen, die wichtig sind.

### php.ini

Unter `/etc/php/8.3/fpm/php.ini` folgende Werte sicherstellen:

```ini
output_buffering = Off
implicit_flush = On
```

Zusätzlich überprüfen:

```ini
zlib.output_compression = Off
```

Restart:

```bash
systemctl restart php8.3-fpm
```

---

# 5. Zentrale, globale SSE-Konfiguration für Apache (empfohlen)

Die zuverlässigste Methode ist eine **globale Konfiguration**, die ALLE PHP-Skripte unterstützt, ohne den VHost aufzublähen.

Datei anlegen:

```
/etc/apache2/conf-available/sse.conf
```

Inhalt:

```apache
# Universelle SSE-Unterstützung für PHP-FPM
<IfModule proxy_fcgi_module>
    # PHP-FPM Buffering abschalten
    ProxyFCGISetEnvIf "true" NO_FCGI_BUFFERING=1
    ProxyFCGISetEnvIf "true" KILL_WORKERS=1
</IfModule>

# Kompression für SSE deaktivieren (sonst bricht der EventStream)
<IfModule mod_headers.c>
    <FilesMatch "\.sse$|sse\.php$|\.stream$">
        Header unset Content-Encoding
        Header set Cache-Control "no-cache"
        Header set X-Accel-Buffering "no"
    </FilesMatch>
</IfModule>
```

Aktivieren:

```bash
a2enconf sse
systemctl restart apache2
```

Diese Datei sollte **NIEMALS gelöscht werden**, sie macht den Server *SSE-ready*, unabhängig davon, ob gerade ein SSE-Skript existiert.

---

# 6. VHost Beispiel (Port 80 UND 443)

## Port 80 – Redirect

```apache
<VirtualHost *:80>
    ServerName example.com
    Redirect / https://example.com/
</VirtualHost>
```

## Port 443 – Produktion + SSE-ready

### Datei:

`/etc/apache2/sites-available/example.com.conf`

```apache
<IfModule mod_ssl.c>
<VirtualHost *:443>

    ServerName example.com
    ServerAdmin webmaster@example.com

    DocumentRoot /var/www/example.com

    <Directory /var/www/example.com>
        Options -Indexes +FollowSymLinks
        AllowOverride All
        Require all granted
        DirectoryIndex index.php index.html
    </Directory>

    # PHP-FPM Handler
    <FilesMatch "\.php$">
        SetHandler "proxy:unix:/run/php/php8.3-fpm.sock|fcgi://localhost/"
    </FilesMatch>

    # Brotli/Deflate NUR für Normal-Pages – NICHT SSE
    <IfModule mod_brotli.c>
        AddOutputFilterByType BROTLI_COMPRESS text/html text/plain text/css application/javascript application/json
    </IfModule>

    <IfModule mod_deflate.c>
        AddOutputFilterByType DEFLATE text/html text/plain text/css application/javascript application/json
    </IfModule>

    Protocols h2 http/1.1

    # Security Headers
    Header always set Strict-Transport-Security "max-age=63072000; includeSubDomains; preload"
    Header always set X-Frame-Options "DENY"
    Header always set X-Content-Type-Options "nosniff"
    Header always set Referrer-Policy "no-referrer-when-downgrade"
    Header always set Permissions-Policy "geolocation=(), microphone=(), camera=()"

    # SSL
    SSLCertificateFile /etc/letsencrypt/live/example.com/fullchain.pem
    SSLCertificateKeyFile /etc/letsencrypt/live/example.com/privkey.pem
    Include /etc/letsencrypt/options-ssl-apache.conf

    ErrorLog ${APACHE_LOG_DIR}/example.error.log
    CustomLog ${APACHE_LOG_DIR}/example.access.log combined

</VirtualHost>
</IfModule>
```

---

# 7. SSE-PHP-Datei (Referenz, funktioniert 1:1)

```php
<?php
while (ob_get_level() > 0) {
    ob_end_clean();
}

header('Content-Type: text/event-stream');
header('Cache-Control: no-cache');
header('X-Accel-Buffering: no');
header('Connection: keep-alive');

ini_set('implicit_flush', '1');
ini_set('output_buffering', 'off');
ob_implicit_flush(1);

for ($i = 1; $i <= 10; $i++) {
    echo "event: tick\n";
    echo "data: {\"i\": $i, \"t\": " . microtime(true) . "}\n\n";
    flush();
    if (connection_aborted()) break;
    sleep(1);
}

echo "event: done\n";
echo "data: {\"status\": \"complete\"}\n\n";
flush();
```

---

# 8. Typische Fehler & ihre Lösungen

## ❌ Event kommt erst nach 10 Sekunden

**Ursache: Pufferung aktiv**
→ Dein Apache oder FPM puffert noch

Lösung:

* Prüfe `/etc/apache2/conf-enabled/sse.conf`
* Prüfe Brotli/Deflate nicht aktiv für SSE
* `ProxyFCGISetEnvIf` muss laufen
* Keine Kompression aktiv

## ❌ SSE wird als Download angezeigt (Binary-Geschwurbel)

**Ursache:** Kompression aktiv → zerstört Framegrenzen

Lösung:

```apache
Header unset Content-Encoding
```

## ❌ Firefox öffnet SSE nur gelegentlich

HTTP/2 kann zickig sein, aber meistens OK.

Wenn nötig auf HTTP/1.1 zwingen:

```apache
Protocols http/1.1
```

## ❌ ModSecurity blockiert SSE

Workaround:

```apache
SecRuleEngine Off
```

Oder nur für eine Location.

---

# 9. Testen ob Flushing funktioniert

```bash
curl -N https://example.com/sse.php
```

Muss **Ereignis für Ereignis erscheinen**, NICHT gesammelt.

---

# 10. Abschluss: Was bleibt dauerhaft?

Die Datei:

```
/etc/apache2/conf-available/sse.conf
```

MUSS bleiben.

Warum?

* Sie macht den gesamten Server SSE-fähig
* Sie zerstört NICHT normale PHP-Anfragen
* Sie muss nie wieder angefasst werden
* Sie verhindert FPM-Pufferung global
* Sie verbessert Stabilität für jeden zukünftigen SSE-Endpoint

Der Rest ist Standard-VHost-Konfiguration.

---

# 11. Minimal-Checkliste: Wenn du einen neuen Server aufsetzt

Folge dieser Reihenfolge:

### ✔ Module aktivieren

```
a2enmod proxy proxy_fcgi headers http2 deflate brotli
```

### ✔ `sse.conf` aktivieren

```
a2enconf sse
```

### ✔ php.ini anpassen

```
output_buffering = Off
implicit_flush = On
zlib.output_compression = Off
```

### ✔ VHost einrichten

(HTTPS + PHP-FPM)

### ✔ Dienste neustarten

```
systemctl restart php8.3-fpm
systemctl restart apache2
```

### ✔ Testen

```
curl -N https://example.com/sse.php
```

Wenn Events einzeln kommen → DER SERVER IST SSE-FÄHIG.
