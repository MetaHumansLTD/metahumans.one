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
        $existing = $this->database->fetchOne(
            'SELECT * FROM provider_accounts WHERE code = :code LIMIT 1',
            ['code' => $code],
        );

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
}
