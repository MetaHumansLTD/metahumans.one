<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;

final class CustomerRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function findOrCreateByEmail(array $payload): array
    {
        $email = strtolower(trim((string) ($payload['email'] ?? '')));
        $tenantId = trim((string) ($payload['tenant_id'] ?? ''));
        $ownerType = trim((string) ($payload['owner_type'] ?? 'user'));
        $ownerId = trim((string) ($payload['owner_id'] ?? ''));

        $existing = $this->database->fetchOne(
            'SELECT * FROM customers WHERE tenant_id = :tenant_id AND email = :email LIMIT 1',
            [
                'tenant_id' => $tenantId,
                'email' => $email,
            ],
        );

        if ($existing !== null) {
            $this->database->execute(
                'UPDATE customers
                 SET owner_type = :owner_type,
                     owner_id = :owner_id,
                     platform_user_id = :platform_user_id,
                     platform_company_id = :platform_company_id,
                     platform_persona_id = :platform_persona_id,
                     first_name = :first_name,
                     last_name = :last_name,
                     company_name = :company_name,
                     metadata_json = :metadata_json,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'id' => $existing['id'],
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'platform_user_id' => $payload['platform_user_id'] ?? null,
                    'platform_company_id' => $payload['platform_company_id'] ?? null,
                    'platform_persona_id' => $payload['platform_persona_id'] ?? null,
                    'first_name' => trim((string) ($payload['first_name'] ?? '')),
                    'last_name' => trim((string) ($payload['last_name'] ?? '')),
                    'company_name' => trim((string) ($payload['company_name'] ?? '')),
                    'metadata_json' => json_encode([
                        'tenant_id' => $tenantId,
                        'owner_type' => $ownerType,
                        'owner_id' => $ownerId,
                    ], JSON_UNESCAPED_SLASHES),
                ],
            );

            return $this->database->fetchOne(
                'SELECT * FROM customers WHERE id = :id LIMIT 1',
                ['id' => $existing['id']],
            ) ?? $existing;
        }

        $record = [
            'id' => Uuid::v4(),
            'tenant_id' => $tenantId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'platform_user_id' => $payload['platform_user_id'] ?? null,
            'platform_company_id' => $payload['platform_company_id'] ?? null,
            'platform_persona_id' => $payload['platform_persona_id'] ?? null,
            'email' => $email,
            'first_name' => trim((string) ($payload['first_name'] ?? '')),
            'last_name' => trim((string) ($payload['last_name'] ?? '')),
            'company_name' => trim((string) ($payload['company_name'] ?? '')),
            'metadata_json' => json_encode([
                'tenant_id' => $tenantId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ], JSON_UNESCAPED_SLASHES),
        ];

        $this->database->execute(
            'INSERT INTO customers (
                id, tenant_id, owner_type, owner_id, platform_user_id, platform_company_id, platform_persona_id,
                email, first_name, last_name, company_name, metadata_json, created_at, updated_at
             ) VALUES (
                :id, :tenant_id, :owner_type, :owner_id, :platform_user_id, :platform_company_id, :platform_persona_id,
                :email, :first_name, :last_name, :company_name, :metadata_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )',
            $record,
        );

        return $record;
    }

    public function findById(string $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM customers WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
    }
}
