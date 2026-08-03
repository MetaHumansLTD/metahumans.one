<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;

final class PricingRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    public function upsertTld(string $tld, string $providerCode, string $currencyCode = 'ZAR'): array
    {
        $existing = $this->database->fetchOne(
            'SELECT * FROM tlds WHERE tld = :tld LIMIT 1',
            ['tld' => $tld],
        );

        if ($existing !== null) {
            return $existing;
        }

        $record = [
            'id' => Uuid::v4(),
            'tld' => $tld,
            'provider_code' => $providerCode,
            'currency_code' => $currencyCode,
        ];

        $this->database->execute(
            'INSERT INTO tlds (id, tld, provider_code, currency_code, created_at, updated_at)
             VALUES (:id, :tld, :provider_code, :currency_code, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP)',
            $record,
        );

        return $record;
    }

    /**
     * @param array<string, mixed> $snapshot
     */
    public function addSnapshot(string $tldId, string $providerAccountId, array $snapshot): void
    {
        $this->database->execute(
            'INSERT INTO tld_price_snapshots (
                id, tld_id, provider_account_id, source, registration_price, renewal_price,
                transfer_price, restore_price, public_registration_price, public_renewal_price,
                public_transfer_price, currency_code, effective_at, created_at
             ) VALUES (
                :id, :tld_id, :provider_account_id, :source, :registration_price, :renewal_price,
                :transfer_price, :restore_price, :public_registration_price, :public_renewal_price,
                :public_transfer_price, :currency_code, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )',
            [
                'id' => Uuid::v4(),
                'tld_id' => $tldId,
                'provider_account_id' => $providerAccountId,
                'source' => $snapshot['source'] ?? 'provider-sync',
                'registration_price' => $snapshot['registration_price'] ?? null,
                'renewal_price' => $snapshot['renewal_price'] ?? null,
                'transfer_price' => $snapshot['transfer_price'] ?? null,
                'restore_price' => $snapshot['restore_price'] ?? null,
                'public_registration_price' => $snapshot['public_registration_price'] ?? $snapshot['registration_price'] ?? null,
                'public_renewal_price' => $snapshot['public_renewal_price'] ?? $snapshot['renewal_price'] ?? null,
                'public_transfer_price' => $snapshot['public_transfer_price'] ?? $snapshot['transfer_price'] ?? null,
                'currency_code' => $snapshot['currency_code'] ?? 'ZAR',
            ],
        );
    }
}
