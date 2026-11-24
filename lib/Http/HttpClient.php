<?php
/**
 * Minimal JSON HTTP client.
 *
 * Uses cURL when available, otherwise falls back to the stream wrapper
 * (allow_url_fopen). This matters on IIS where one extension is often enabled
 * and the other is not. PHP 7.4 compatible.
 */

namespace Operators\Http;

class HttpClient
{
    /** @var int */
    private $timeout;

    /** @var int */
    private $connectTimeout;

    public function __construct($timeout = 8, $connectTimeout = 3)
    {
        $this->timeout = (int) $timeout;
        $this->connectTimeout = (int) $connectTimeout;
    }

    /**
     * GET a JSON document, retrying transient failures.
     *
     * @param string $url
     * @param array<string,mixed> $query
     * @param int $attempts
     * @return array{ok:bool,status:?int,body:string,json:mixed,error:?string,url:string,ms:float,transport:string}
     */
    public function getJson($url, array $query = [], $attempts = 1)
    {
        return $this->requestJson('GET', $url, $query, null, $attempts);
    }

    /**
     * Send a JSON body (or no body) with an arbitrary HTTP method. Write
     * endpoints may legitimately answer with an empty 204 or plain text, so
     * only GET requires a JSON response.
     *
     * @param string $method
     * @param string $url
     * @param mixed  $body
     * @param array<string,mixed> $query
     * @param int    $attempts
     * @return array{ok:bool,status:?int,body:string,json:mixed,error:?string,url:string,ms:float,transport:string}
     */
    public function sendJson($method, $url, $body = null, array $query = [], $attempts = 1)
    {
        return $this->requestJson(strtoupper((string) $method), $url, $query, $body, $attempts);
    }

    /**
     * @param string $method
     * @param string $url
     * @param array<string,mixed> $query
     * @param mixed $body
     * @param int $attempts
     * @return array{ok:bool,status:?int,body:string,json:mixed,error:?string,url:string,ms:float,transport:string}
     */
    private function requestJson($method, $url, array $query, $body, $attempts)
    {
        $method = strtoupper((string) $method);
        $full = $query ? $url . '?' . http_build_query($query) : $url;
        $attempts = max(1, (int) $attempts);

        if ($body === null || is_string($body)) {
            $payload = $body;
        } else {
            $encoded = json_encode($body, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
            $payload = $encoded === false ? null : $encoded;
        }

        $last = null;

        for ($attempt = 1; $attempt <= $attempts; $attempt++) {
            $last = $this->request($full, $method, $payload);

            if ($last['ok']) {
                $bodyText = trim((string) $last['body']);

                if ($bodyText === '') {
                    if ($method !== 'GET') {
                        $last['json'] = null;
                        return $last;
                    }
                    $last['ok'] = false;
                    $last['error'] = 'invalid_json: empty body';
                } else {
                    $decoded = json_decode($last['body'], true);
                    if (json_last_error() === JSON_ERROR_NONE) {
                        $last['json'] = $decoded;
                        return $last;
                    }

                    if ($method !== 'GET') {
                        // Some write endpoints answer with plain text.
                        $last['json'] = null;
                        return $last;
                    }

                    $last['ok'] = false;
                    $last['error'] = 'invalid_json: ' . json_last_error_msg();
                }
            }

            // Do not retry client errors (4xx) - only transport/5xx failures.
            if ($last['status'] !== null && $last['status'] >= 400 && $last['status'] < 500) {
                break;
            }
            if ($attempt < $attempts) {
                usleep(150000);
            }
        }

        return $last;
    }

    /**
     * @param string $url
     * @return array{ok:bool,status:?int,body:string,json:mixed,error:?string,url:string,ms:float,transport:string}
     */
    private function request($url, $method = 'GET', $body = null)
    {
        $started = microtime(true);

        if (function_exists('curl_init')) {
            $result = $this->requestWithCurl($url, $method, $body);
        } elseif (ini_get('allow_url_fopen')) {
            $result = $this->requestWithStream($url, $method, $body);
        } else {
            $result = ['status' => null, 'body' => '', 'error' => 'no_transport (enable curl or allow_url_fopen)'];
        }

        $result['ok'] = ($result['status'] !== null && $result['status'] >= 200 && $result['status'] < 300);
        if (!$result['ok'] && empty($result['error'])) {
            $result['error'] = 'http_status_' . $result['status'];
        }

        return array_merge([
            'status'    => null,
            'body'      => '',
            'json'      => null,
            'error'     => null,
            'transport' => 'none',
        ], $result, [
            'url' => $url,
            'ms'  => round((microtime(true) - $started) * 1000, 1),
        ]);
    }

    private function requestWithCurl($url, $method = 'GET', $body = null)
    {
        $headers = ['Accept: application/json'];
        if ($body !== null) {
            $headers[] = 'Content-Type: application/json';
        }

        $ch = curl_init($url);
        $options = [
            CURLOPT_RETURNTRANSFER => true,
            CURLOPT_TIMEOUT        => $this->timeout,
            CURLOPT_CONNECTTIMEOUT => $this->connectTimeout,
            CURLOPT_FOLLOWLOCATION => false,
            CURLOPT_HTTPHEADER     => $headers,
            CURLOPT_ENCODING       => '',
            CURLOPT_CUSTOMREQUEST  => $method,
        ];
        if ($body !== null) {
            $options[CURLOPT_POSTFIELDS] = $body;
        }
        curl_setopt_array($ch, $options);

        $body = curl_exec($ch);
        $status = curl_getinfo($ch, CURLINFO_HTTP_CODE);
        $error = curl_errno($ch) ? ('curl_' . curl_errno($ch) . ': ' . curl_error($ch)) : null;
        curl_close($ch);

        if ($body === false) {
            return ['status' => $status ?: null, 'body' => '', 'error' => $error ?: 'curl_error', 'transport' => 'curl'];
        }

        return ['status' => $status ?: null, 'body' => (string) $body, 'error' => $error, 'transport' => 'curl'];
    }

    private function requestWithStream($url, $method = 'GET', $body = null)
    {
        $headers = "Accept: application/json\r\n";
        if ($body !== null) {
            $headers .= "Content-Type: application/json\r\n";
        }

        $http = [
            'method'        => $method,
            'timeout'       => $this->timeout,
            'ignore_errors' => true,
            'header'        => $headers,
        ];
        if ($body !== null) {
            $http['content'] = $body;
        }

        $context = stream_context_create([
            'http' => $http,
            'ssl' => [
                'verify_peer'      => true,
                'verify_peer_name' => true,
            ],
        ]);

        $body = @file_get_contents($url, false, $context);

        $status = null;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $header) {
                if (preg_match('#^HTTP/\S+\s+(\d{3})#', $header, $m)) {
                    $status = (int) $m[1];
                }
            }
        }

        if ($body === false) {
            return ['status' => $status, 'body' => '', 'error' => 'stream_request_failed', 'transport' => 'stream'];
        }

        return ['status' => $status, 'body' => (string) $body, 'error' => null, 'transport' => 'stream'];
    }
}

