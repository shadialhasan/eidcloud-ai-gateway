<?php

declare(strict_types=1);

namespace EidCloud\AiGateway\Providers;

abstract class AbstractProvider implements ProviderInterface
{
    protected string $name;
    protected string $baseUrl;
    protected ?string $apiKey;
    protected int $timeout;

    public function __construct(string $name, string $baseUrl, ?string $apiKey = null, int $timeout = 30)
    {
        $this->name = $name;
        $this->baseUrl = rtrim($baseUrl, '/');
        $this->apiKey = $apiKey;
        $this->timeout = $timeout;
    }

    public function getName(): string
    {
        return $this->name;
    }

    public function getBaseUrl(): string
    {
        return $this->baseUrl;
    }

    public function estimateTokens(array|string $input): int
    {
        if (is_array($input)) {
            $text = '';
            foreach ($input as $msg) {
                if (is_array($msg)) {
                    $text .= ($msg['content'] ?? '') . ' ';
                } elseif (is_string($msg)) {
                    $text .= $msg . ' ';
                }
            }
        } else {
            $text = (string)$input;
        }

        // Fast approximation ~ 4 characters per token
        $len = mb_strlen(trim($text));
        return max(1, (int)ceil($len / 4));
    }

    /**
     * Internal cURL wrapper (or file_get_contents fallback) for zero dependencies.
     */
    protected function makeHttpRequest(string $url, string $method = 'GET', ?array $body = null, array $extraHeaders = []): array
    {
        $headers = array_merge([
            'Content-Type: application/json',
            'Accept: application/json',
            'User-Agent: EidCloud-AiGateway/1.0.0 (PHP 8.2+)',
        ], $extraHeaders);

        if ($this->apiKey !== null && $this->apiKey !== '') {
            $headers[] = 'Authorization: Bearer ' . $this->apiKey;
        }

        $jsonBody = ($body !== null) ? json_encode($body) : null;

        if (extension_loaded('curl')) {
            $ch = curl_init($url);
            curl_setopt($ch, CURLOPT_RETURNTRANSFER, true);
            curl_setopt($ch, CURLOPT_TIMEOUT, $this->timeout);
            curl_setopt($ch, CURLOPT_HTTPHEADER, $headers);
            curl_setopt($ch, CURLOPT_CUSTOMREQUEST, $method);

            if ($jsonBody !== null) {
                curl_setopt($ch, CURLOPT_POSTFIELDS, $jsonBody);
            }

            // Health checks or SSL verification
            curl_setopt($ch, CURLOPT_SSL_VERIFYPEER, false);
            curl_setopt($ch, CURLOPT_SSL_VERIFYHOST, 0);

            $response = curl_exec($ch);
            $httpCode = curl_getinfo($ch, CURLINFO_HTTP_CODE);
            $error = curl_error($ch);
            curl_close($ch);

            if ($response === false) {
                throw new \RuntimeException("Provider [{$this->name}] HTTP request failed: {$error}");
            }

            return ['code' => $httpCode, 'body' => (string)$response];
        }

        // Native stream context fallback if curl is not loaded
        $opts = [
            'http' => [
                'method' => $method,
                'header' => implode("\r\n", $headers),
                'timeout' => $this->timeout,
                'ignore_errors' => true,
            ],
            'ssl' => [
                'verify_peer' => false,
                'verify_peer_name' => false,
            ]
        ];

        if ($jsonBody !== null) {
            $opts['http']['content'] = $jsonBody;
        }

        $context = stream_context_create($opts);
        $response = @file_get_contents($url, false, $context);

        $httpCode = 0;
        if (isset($http_response_header) && is_array($http_response_header)) {
            foreach ($http_response_header as $hdr) {
                if (preg_match('#HTTP/\S+\s+(\d+)#', $hdr, $m)) {
                    $httpCode = (int)$m[1];
                }
            }
        }

        if ($response === false) {
            throw new \RuntimeException("Provider [{$this->name}] stream request failed for URL: {$url}");
        }

        return ['code' => $httpCode, 'body' => (string)$response];
    }
}
