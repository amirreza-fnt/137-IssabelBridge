<?php
declare(strict_types=1);

/**
 * Minimal HTTP helper (curl). No Composer dependency — runs on stock Issabel PHP.
 */
final class HttpClient
{
    public function __construct(
        private readonly int $timeout = 60
    ) {}

    /** @return array{status:int, body:string, json:?array} */
    public function request(
        string $method,
        string $url,
        ?array $jsonBody = null,
        array $headers = [],
        ?array $multipart = null
    ): array {
        $ch = curl_init($url);
        if ($ch === false) {
            throw new RuntimeException("curl_init failed for {$url}");
        }

        $hdrs = $headers;
        $opts = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_CUSTOMREQUEST  => strtoupper($method),
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => 10,
            CURLOPT_FOLLOWLOCATION => false,
        ];

        if ($multipart !== null) {
            $opts[CURLOPT_POSTFIELDS] = $multipart;
        } elseif ($jsonBody !== null) {
            $payload = json_encode($jsonBody, JSON_UNESCAPED_UNICODE);
            $opts[CURLOPT_POSTFIELDS] = $payload;
            $hdrs[] = 'Content-Type: application/json';
            $hdrs[] = 'Content-Length: ' . strlen((string)$payload);
        }

        if ($hdrs !== []) {
            $opts[CURLOPT_HTTPHEADER] = $hdrs;
        }

        curl_setopt_array($ch, $opts);
        $body = curl_exec($ch);
        $errno = curl_errno($ch);
        $err = curl_error($ch);
        $status = (int)curl_getinfo($ch, CURLINFO_HTTP_CODE);
        curl_close($ch);

        if ($errno !== 0) {
            throw new RuntimeException("HTTP {$method} {$url} failed: {$err}");
        }

        $decoded = null;
        if (is_string($body) && $body !== '') {
            $tmp = json_decode($body, true);
            if (json_last_error() === JSON_ERROR_NONE) {
                $decoded = $tmp;
            }
        }

        return ['status' => $status, 'body' => is_string($body) ? $body : '', 'json' => $decoded];
    }
}
