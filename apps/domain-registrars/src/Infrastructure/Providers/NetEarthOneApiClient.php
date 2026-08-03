<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use RuntimeException;

final class NetEarthOneApiClient
{
    public function __construct(
        private readonly string $baseUrl,
        private readonly string $authUserId,
        private readonly string $apiKey,
        private readonly int $timeoutSeconds = 30,
    ) {
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
