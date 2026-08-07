<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use RuntimeException;

final class NetEarthOneApiClient
{
    public function __construct(
        string $baseUrl,
        private readonly string $authUserId,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 30,
    ) {
        $this->baseUrl = $this->normalizeBaseUrl($baseUrl);
    }

    private function normalizeBaseUrl(string $baseUrl): string
    {
        $trimmed = rtrim(trim($baseUrl), '/');
        if ($trimmed === '') {
            return '';
        }

        if (preg_match('#/api/?$#', $trimmed) === 1) {
            return $trimmed;
        }

        if (str_ends_with($trimmed, '/api/')) {
            return rtrim($trimmed, '/');
        }

        if (str_contains($trimmed, '/anacreon/') || str_contains($trimmed, '.xml')) {
            $host = parse_url($trimmed, PHP_URL_HOST);
            $scheme = parse_url($trimmed, PHP_URL_SCHEME);
            if (is_string($host) && $host !== '' && is_string($scheme)) {
                return $scheme . '://' . $host . '/api';
            }
        }

        return $trimmed . '/api';
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function get(string $path, array $params = []): array
    {
        return $this->request('GET', $path, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    public function post(string $path, array $params = []): array
    {
        return $this->request('POST', $path, $params);
    }

    /**
     * @param array<string, mixed> $params
     * @return array<string, mixed>
     */
    private function request(string $method, string $path, array $params): array
    {
        $payload = [
            'auth-userid' => $this->authUserId,
            'api-key' => $this->apiKey,
        ] + $params;

        $url = rtrim($this->baseUrl, '/') . '/' . ltrim($path, '/');
        $curl = curl_init();

        if ($curl === false) {
            throw new RuntimeException('Unable to initialize the NetEarthOne HTTP client.');
        }

        $query = $this->buildQuery($payload);
        if ($method === 'GET' && $query !== '') {
            $url .= '?' . $query;
        }

        curl_setopt_array(
            $curl,
            [
                CURLOPT_URL => $url,
                CURLOPT_RETURNTRANSFER => true,
                CURLOPT_FOLLOWLOCATION => true,
                CURLOPT_TIMEOUT => $this->timeoutSeconds,
                CURLOPT_SSL_VERIFYPEER => true,
                CURLOPT_SSL_VERIFYHOST => 2,
                CURLOPT_HTTPHEADER => ['Accept: application/json'],
                CURLOPT_IPRESOLVE => CURL_IPRESOLVE_V4,
            ],
        );

        if ($method === 'POST') {
            curl_setopt($curl, CURLOPT_POST, true);
            curl_setopt($curl, CURLOPT_POSTFIELDS, $query);
            curl_setopt($curl, CURLOPT_HTTPHEADER, [
                'Accept: application/json',
                'Content-Type: application/x-www-form-urlencoded',
            ]);
        }

        $body = curl_exec($curl);
        $httpCode = (int) curl_getinfo($curl, CURLINFO_HTTP_CODE);
        $error = curl_error($curl);
        curl_close($curl);

        if ($body === false) {
            throw new RuntimeException($error !== '' ? $error : 'NetEarthOne request failed.');
        }

        $decoded = json_decode($body, true);
        if (! is_array($decoded)) {
            throw new RuntimeException(sprintf('NetEarthOne returned a non-JSON response for %s.', $path));
        }

        if ($httpCode >= 400) {
            $message = (string) ($decoded['message'] ?? $decoded['error'] ?? ('HTTP ' . $httpCode));
            throw new RuntimeException($message);
        }

        return $decoded;
    }

    public function getAuthUserId(): string
    {
        return $this->authUserId;
    }

    public function maskedAuthUserId(): string
    {
        return self::maskedValue($this->authUserId);
    }

    public function maskedApiKey(): string
    {
        return self::maskedValue($this->apiKey);
    }

    /** @return array{prefix: non-empty-string, suffix: non-empty-string}|array{prefix: null, suffix: null} */
    public function apiKeyPrefixSuffix(): array
    {
        return self::prefixSuffix($this->apiKey);
    }

    /** @return array{prefix: non-empty-string, suffix: non-empty-string}|array{prefix: null, suffix: null} */
    public function authUserIdPrefixSuffix(): array
    {
        return self::prefixSuffix($this->authUserId);
    }

    /**
     * @return array{prefix: non-empty-string, suffix: non-empty-string}|array{prefix: null, suffix: null}
     */
    private static function prefixSuffix(string $value): array
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return ['prefix' => null, 'suffix' => null];
        }
        $n = strlen($trimmed);
        if ($n <= 8) {
            return ['prefix' => $trimmed, 'suffix' => $trimmed];
        }
        return ['prefix' => substr($trimmed, 0, 4), 'suffix' => substr($trimmed, -4)];
    }

    private static function maskedValue(string $value): string
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return 'Not configured';
        }
        $n = strlen($trimmed);
        if ($n <= 4) {
            return str_repeat('•', $n);
        }

        return substr($trimmed, 0, 2) . str_repeat('•', max(1, $n - 4)) . substr($trimmed, -2);
    }

    /**
     * @param array<string, mixed> $params
     */
    private function buildQuery(array $params): string
    {
        $pairs = [];

        foreach ($params as $key => $value) {
            $this->appendQueryPairs($pairs, (string) $key, $value);
        }

        return implode('&', $pairs);
    }

    /**
     * @param list<string> $pairs
     */
    private function appendQueryPairs(array &$pairs, string $key, mixed $value): void
    {
        if (is_array($value)) {
            foreach ($value as $item) {
                $this->appendQueryPairs($pairs, $key, $item);
            }

            return;
        }

        if (is_bool($value)) {
            $value = $value ? 'true' : 'false';
        }

        if ($value === null) {
            return;
        }

        $pairs[] = rawurlencode($key) . '=' . rawurlencode((string) $value);
    }
}
