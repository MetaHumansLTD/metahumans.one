<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;

final class ProviderAccountRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function getOrCreate(string $code, string $displayName, string $driverClass): array
    {
        $existing = $this->findByCode($code);

        if ($existing !== null) {
            return $existing;
        }

        $record = [
            'id' => Uuid::v4(),
            'code' => $code,
            'display_name' => $displayName,
            'driver_class' => $driverClass,
        ];

        $this->database->execute(
            'INSERT INTO provider_accounts (id, code, display_name, driver_class, created_at, updated_at)
             VALUES (:id, :code, :display_name, :driver_class, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            $record,
        );

        return $this->database->fetchOne(
            'SELECT * FROM provider_accounts WHERE id = :id LIMIT 1',
            ['id' => $record['id']],
        ) ?? $record;
    }

    public function findByCode(string $code): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM provider_accounts WHERE code = :code LIMIT 1',
            ['code' => $code],
        );
    }

    /**
     * @param array<string, mixed> $fields
     * @param array<string, mixed> $config
     */
    public function updateSettings(string $id, array $fields, array $config): ?array
    {
        $payload = [
            'id' => $id,
            'is_active' => ! empty($fields['is_active']) ? 1 : 0,
            'environment' => $this->sanitizeNullableString($fields['environment'] ?? null) ?? 'production',
            'credentials_secret_ref' => $this->sanitizeNullableString($fields['credentials_secret_ref'] ?? null),
            'config_json' => json_encode($config, JSON_UNESCAPED_SLASHES),
        ];

        $this->database->execute(
            'UPDATE provider_accounts
                SET is_active = :is_active,
                    environment = :environment,
                    credentials_secret_ref = :credentials_secret_ref,
                    config_json = :config_json,
                    updated_at = CURRENT_TIMESTAMP
              WHERE id = :id',
            $payload,
        );

        return $this->database->fetchOne(
            'SELECT * FROM provider_accounts WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function decodeConfig(?array $providerAccount): array
    {
        if (! is_array($providerAccount)) {
            return [];
        }

        $configJson = $providerAccount['config_json'] ?? null;
        if (! is_string($configJson) || trim($configJson) === '') {
            return [];
        }

        $decoded = json_decode($configJson, true);

        return is_array($decoded) ? $decoded : [];
    }

    private function sanitizeNullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
