<?php declare(strict_types=1);

namespace EventTransport\Service;

use Base3\Api\IOutput;
use Base3\Api\IRequest;

/**
 * Stream-Endpoint für POST-SSE:
 * Holt die gespeicherte Anfrage aus der Session,
 * ruft den eigentlichen SSE-Service per POST auf
 * und leitet den SSE-Body 1:1 an den Browser weiter.
 */
class EventTransportPostSse implements IOutput {

        private IRequest $request;

        public function __construct(IRequest $request) {
                $this->request = $request;
        }

        public static function getName(): string {
            return 'eventtransportpostsse';
        }

        public function getOutput($out = 'html'): string {

                if (session_status() !== PHP_SESSION_ACTIVE) {
                        session_start();
                }

                $id = (string)($this->request->get('id') ?? '');

                // Kein oder ungültiger Eintrag → minimaler SSE-Fehler
                if ($id === '' || !isset($_SESSION['eventtransport_postsse'][$id])) {
                        $this->startHeaders();
                        echo "event: error\n";
                        echo 'data: ' . json_encode(['error' => 'Invalid or expired stream id'], JSON_UNESCAPED_UNICODE) . "\n\n";
                        flush();
                        return '';
                }

                $entry = $_SESSION['eventtransport_postsse'][$id];
                // Direkt löschen, damit der Eintrag nicht erneut benutzt wird
                unset($_SESSION['eventtransport_postsse'][$id]);

                $endpoint = (string)($entry['endpoint'] ?? '');
                $payload  = (array)($entry['payload'] ?? []);

                $this->startHeaders();

                // Ziel-URL relativ zu aktueller Base auflösen
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // i.d.R. ''

                $url    = $scheme . '://' . $host . $base . '/' . ltrim($endpoint, '/');

                $ch = curl_init();
                curl_setopt_array($ch, [
                        CURLOPT_URL            => $url,
                        CURLOPT_POST           => true,
                        CURLOPT_POSTFIELDS     => http_build_query($payload, '', '&'),
                        CURLOPT_HTTPHEADER     => [
                                'Accept: text/event-stream'
                        ],
                        CURLOPT_RETURNTRANSFER => false, // wir streamen direkt
                        CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {

                                if (connection_aborted() === 1) {
                                        return 0;
                                }

                                // Upstream-SSE 1:1 weiterleiten
                                echo $chunk;
                                flush();
                                return strlen($chunk);
                        },
                        CURLOPT_TIMEOUT        => 0
                ]);

                curl_exec($ch);
                curl_close($ch);

                // KEIN zusätzliches "done" hier –
                // der eigentliche Service (z.B. DemoServiceAgentStream)
                // sendet sein eigenes done-Event.

                return '';
        }

        private function startHeaders(): void {

                while (ob_get_level() > 0) {
                        @ob_end_clean();
                }

                if (!headers_sent()) {
                        header('Content-Type: text/event-stream; charset=UTF-8');
                        header('Cache-Control: no-cache');
                        header('X-Accel-Buffering: no');
                        header('Connection: keep-alive');
                }

                @ini_set('implicit_flush', '1');
                @ini_set('output_buffering', 'off');
                ob_implicit_flush(true);

                echo "\n";
                flush();
        }

        public function getHelp(): string {
                return 'Bridged SSE-Endpoint für POST-SSE. Nur intern vom EventTransportClient genutzt.';
        }
}
