<?php declare(strict_types=1);

namespace EventTransport\Service;

use Base3\Api\IOutput;
use Base3\Api\IRequest;

/**
 * Stream endpoint for POST-SSE:
 * Reads stored request data from session,
 * calls the real SSE service via POST,
 * and streams its body 1:1 to the browser.
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

		// No or invalid entry → minimal SSE error
		if ($id === '' || !isset($_SESSION['eventtransport_postsse'][$id])) {
			$this->startHeaders();
			echo "event: error\n";
			echo 'data: ' . json_encode(['error' => 'Invalid or expired stream id'], JSON_UNESCAPED_UNICODE) . "\n\n";
			flush();
			return '';
		}

		$entry = $_SESSION['eventtransport_postsse'][$id];
		// Remove immediately so it cannot be reused
		unset($_SESSION['eventtransport_postsse'][$id]);

		// IMPORTANT: release session lock before long streaming
		// but keep session id for upstream call
		$sessionName = session_name();
		$sessionId   = session_id();
		session_write_close();

		$endpoint = (string)($entry['endpoint'] ?? '');
		$payload  = (array)($entry['payload'] ?? []);

		$this->startHeaders();

		// Resolve target URL relative to current base
		$scheme = (!empty($_SERVER['HTTPS']) && $_SERVER['HTTPS'] !== 'off') ? 'https' : 'http';
		$host   = $_SERVER['HTTP_HOST'] ?? 'localhost';
		$base   = rtrim(dirname($_SERVER['SCRIPT_NAME']), '/');

		$url    = $scheme . '://' . $host . $base . '/' . ltrim($endpoint, '/');

		$headers = [
			'Accept: text/event-stream'
		];

		// Forward PHP session cookie to upstream SSE endpoint
		if ($sessionId !== '') {
			$headers[] = 'Cookie: ' . $sessionName . '=' . $sessionId;
		}

		$ch = curl_init();
		curl_setopt_array($ch, [
			CURLOPT_URL            => $url,
			CURLOPT_POST           => true,
			CURLOPT_POSTFIELDS     => http_build_query($payload, '', '&'),
			CURLOPT_HTTPHEADER     => $headers,
			CURLOPT_RETURNTRANSFER => false, // stream directly
			CURLOPT_WRITEFUNCTION  => function ($ch, $chunk) {

				if (connection_aborted() === 1) {
					return 0;
				}

				// Forward upstream SSE chunk 1:1
				echo $chunk;
				flush();
				return strlen($chunk);
			},
			CURLOPT_TIMEOUT        => 0
		]);

		curl_exec($ch);
		curl_close($ch);

		// No extra "done" here –
		// the actual service (e.g. DemoServiceAgentStream)
		// sends its own done event.

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
		return 'Bridged SSE endpoint for POST-SSE. Used internally by EventTransportClient.';
	}
}
