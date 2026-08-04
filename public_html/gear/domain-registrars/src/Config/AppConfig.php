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
        $value = $_ENV[$key] ?? $_SERVER[$key] ?? getenv($key);

        if ($value === false) {
            return null;
        }

        return is_string($value) ? $value : null;
    }
}
