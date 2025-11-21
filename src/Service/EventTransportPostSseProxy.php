<?php declare(strict_types=1);

namespace EventTransport\Service;

use Base3\Api\IOutput;
use Base3\Api\IRequest;

/**
 * Proxy-Endpoint für POST-SSE:
 * Nimmt POST-JSON entgegen (endpoint + payload),
 * legt alles in der Session ab und liefert eine Stream-URL zurück.
 */
class EventTransportPostSseProxy implements IOutput {

        private IRequest $request;

        public function __construct(IRequest $request) {
                $this->request = $request;
        }

        public static function getName(): string {
                return 'eventtransportpostsseproxy';
        }

        public function getOutput($out = 'html'): string {

                if (session_status() !== PHP_SESSION_ACTIVE) {
                        session_start();
                }

                $raw = file_get_contents('php://input') ?: '';
                $data = json_decode($raw, true);

                if (!is_array($data)) {
                        return $this->jsonError('Invalid JSON payload');
                }

                $endpoint = trim((string)($data['endpoint'] ?? ''));
                $payload  = $data['payload'] ?? [];

                if ($endpoint === '' || !is_array($payload)) {
                        return $this->jsonError('Missing endpoint or payload');
                }

                // Eindeutige ID für diesen Stream
                $id = bin2hex(random_bytes(16));

                if (!isset($_SESSION['eventtransport_postsse'])) {
                        $_SESSION['eventtransport_postsse'] = [];
                }

                $_SESSION['eventtransport_postsse'][$id] = [
                        'endpoint' => $endpoint,
                        'payload'  => $payload,
                        'created'  => time()
                ];

                // Stream-URL aufbauen, analog zu Base3-Routing
                $scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
                $host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
                $base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/'); // meist '' oder '/subdir'

                $path   = $base . '/eventtransportpostsse.php?id=' . urlencode($id);
                $streamUrl = $scheme . '://' . $host . $path;

                return json_encode([
                        'ok'     => true,
                        'id'     => $id,
                        'stream' => $streamUrl
                ], JSON_UNESCAPED_UNICODE);
        }

        private function jsonError(string $msg): string {
                return json_encode([
                        'ok'    => false,
                        'error' => $msg
                ], JSON_UNESCAPED_UNICODE);
        }

        public function getHelp(): string {
                return 'POST JSON {endpoint, payload} und erhalte {ok, id, stream}.';
        }
}
