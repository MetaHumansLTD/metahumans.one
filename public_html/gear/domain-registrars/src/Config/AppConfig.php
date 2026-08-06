<?php

declare(strict_types=1);

namespace App\Config;

use RuntimeException;

final class AppConfig
{
    public function string(string $key, ?string $default = null): string
    {
        $value = $this->value($key);

        if ($value === null || $value === '') {
            if ($default !== null) {
                return $default;
            }

            throw new RuntimeException(sprintf('Missing required configuration value "%s".', $key));
        }

        return $value;
    }

    public function nullableString(string $key): ?string
    {
        $value = $this->value($key);

        return $value === null || $value === '' ? null : $value;
    }

    public function int(string $key, int $default): int
    {
        $value = $this->value($key);

        return $value === null || $value === '' ? $default : (int) $value;
    }

    public function bool(string $key, bool $default): bool
    {
        $value = $this->value($key);

        if ($value === null || $value === '') {
            return $default;
        }

        return in_array(strtolower($value), ['1', 'true', 'yes', 'on'], true);
    }

    /**
     * @return list<string>
     */
    public function csv(string $key): array
    {
        $value = $this->value($key);

        if ($value === null || trim($value) === '') {
            return [];
        }

        $parts = array_map('trim', explode(',', $value));
        $parts = array_filter($parts, static fn (string $part): bool => $part !== '');

        return array_values($parts);
    }

    private function value(string $key): ?string
    {
        $value = getenv($key);
        if (is_string($value) && $value !== '') {
            return $value;
        }

        if (isset($_ENV[$key]) && is_string($_ENV[$key]) && $_ENV[$key] !== '') {
            return $_ENV[$key];
        }

        if (isset($_SERVER[$key]) && is_string($_SERVER[$key]) && $_SERVER[$key] !== '') {
            return $_SERVER[$key];
        }

        return null;
    }
}
