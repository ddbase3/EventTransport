<?php declare(strict_types=1);

namespace EventTransport\Stream;

use EventTransport\Api\IEventStream;

/**
 * Server-Sent Events stream (SSE).
 * Real streaming if the server supports it.
 */
class SseEventStream implements IEventStream {

        private bool $started = false;
        private bool $finished = false;

	// NOTE:
	// Padding is intentionally disabled by default.
	// This exists as a last-resort workaround for aggressive proxy/FCGI buffering.

        /**
         * Padding bytes to avoid upstream buffering (FCGI/proxy thresholds).
         * 0 disables padding.
         */
        private int $padBytes = 0;

        /**
         * Only pad every N token events (to reduce bandwidth).
         * 1 = every token, 10 = every 10th token.
         */
        private int $padEvery = 1;

        private int $tokenCount = 0;

	public function start(): void {
		if ($this->started) return;
		$this->started = true;

		// 1. Buffering radikal beenden
		while (ob_get_level() > 0) {
			@ob_end_clean();
		}

		// 2. Kompression verhindern (WICHTIG für Apache + Proxy)
		if (function_exists('apache_setenv')) {
			@apache_setenv('no-gzip', '1');
		}
		@ini_set('zlib.output_compression', '0');

		header_remove("Content-Type");
		// Wichtig: kein charset bei event-stream laut Spezifikation, nur Content-Type
		header("Content-Type: text/event-stream");
		header("Cache-Control: no-cache, no-transform"); // no-transform verbietet Proxies das Komprimieren
		header("X-Accel-Buffering: no");
		header('Content-Encoding: none');
		header("Connection: keep-alive");

		@ini_set("implicit_flush", "1");
		@ini_set("output_buffering", "off");
		if (function_exists('ob_implicit_flush')) {
			ob_implicit_flush(true);
		}

		// 3. Den Caddy/Proxy-Puffer "anstoßen"
		// Manche Proxies brauchen ein paar Bytes, um den Stream zu öffnen
		echo ":" . str_repeat(" ", 2048) . "\n\n"; 
		echo "retry: 2000\n\n";
    
		flush();
	}

        public function push(string $event, array $data): void {
                if ($this->finished) return;
                if (!$this->started) $this->start();
                if ($this->isDisconnected()) return;

                echo "event: {$event}\n";
                echo "data: " . json_encode($data, JSON_UNESCAPED_UNICODE) . "\n\n";

                // Pad only for token events, and only every Nth token.
                if ($event === "token") {
                        $this->tokenCount++;
                        if ($this->padBytes > 0 && $this->padEvery > 0 && ($this->tokenCount % $this->padEvery) === 0) {
                                echo ":" . str_repeat(" ", $this->padBytes) . "\n\n";
                        }
                }

                flush();
        }

        public function sendComment(string $text): void {
                if ($this->finished) return;
                if (!$this->started) $this->start();
                if ($this->isDisconnected()) return;

                echo ": {$text}\n\n";
                flush();
        }

        public function isDisconnected(): bool {
                return connection_aborted() === 1;
        }

        public function finish(array $finalPayload): void {
                if ($this->finished) return;
                $this->finished = true;

                if (!$this->started) {
                        $this->start();
                }

                if (!$this->isDisconnected()) {
                        echo "event: done\n";
                        echo "data: " . json_encode($finalPayload, JSON_UNESCAPED_UNICODE) . "\n\n";
                        flush();
                }
        }
}
