<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;

final class DomainRepository
{
    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $nameservers
     */
    public function createDraft(
        string $providerAccountId,
        string $providerCode,
        string $customerId,
        string $domainName,
        int $periodYears,
        array $payload,
        array $nameservers,
    ): array {
        $tld = str_contains($domainName, '.') ? substr($domainName, strpos($domainName, '.') + 1) : $domainName;
        $existing = $this->database->fetchOne(
            'SELECT * FROM domains WHERE provider_account_id = :provider_account_id AND domain_name = :domain_name LIMIT 1',
            [
                'provider_account_id' => $providerAccountId,
                'domain_name' => strtolower($domainName),
            ],
        );

        if ($existing !== null) {
            $this->database->execute(
                'UPDATE domains
                 SET owner_type = :owner_type,
                     owner_id = :owner_id,
                     acting_user_id = :acting_user_id,
                     acting_persona_id = :acting_persona_id,
                     billing_mode = :billing_mode,
                     billing_tenant_id = :billing_tenant_id,
                     external_action_ref = :external_action_ref,
                     customer_id = :customer_id,
                     provider_code = :provider_code,
                     registrar_status = :registrar_status,
                     metadata_json = :metadata_json,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'id' => $existing['id'],
                    'owner_type' => $payload['owner_type'] ?? 'user',
                    'owner_id' => $payload['owner_id'] ?? $payload['tenant_id'] ?? '',
                    'acting_user_id' => $payload['acting_user_id'] ?? null,
                    'acting_persona_id' => $payload['acting_persona_id'] ?? null,
                    'billing_mode' => $payload['billing_mode'] ?? 'user',
                    'billing_tenant_id' => $payload['billing_tenant_id'] ?? $payload['tenant_id'] ?? '',
                    'external_action_ref' => $payload['reference_id'] ?? null,
                    'customer_id' => $customerId,
                    'provider_code' => $providerCode,
                    'registrar_status' => 'pending_submission',
                    'metadata_json' => json_encode([
                        'period_years' => $periodYears,
                        'draft_payload' => $payload,
                    ], JSON_UNESCAPED_SLASHES),
                ],
            );

            $this->replaceNameservers((string) $existing['id'], $nameservers);

            return $this->findById((string) $existing['id']) ?? $existing;
        }

        $record = [
            'id' => Uuid::v4(),
            'tenant_id' => (string) ($payload['tenant_id'] ?? ''),
            'owner_type' => (string) ($payload['owner_type'] ?? 'user'),
            'owner_id' => (string) ($payload['owner_id'] ?? ($payload['tenant_id'] ?? '')),
            'acting_user_id' => $payload['acting_user_id'] ?? null,
            'acting_persona_id' => $payload['acting_persona_id'] ?? null,
            'billing_mode' => (string) ($payload['billing_mode'] ?? 'user'),
            'billing_tenant_id' => (string) ($payload['billing_tenant_id'] ?? ($payload['tenant_id'] ?? '')),
            'finance_event_ref' => $payload['finance_event_ref'] ?? null,
            'receipt_bundle_path' => $payload['receipt_bundle_path'] ?? null,
            'receipt_bundle_hash' => $payload['receipt_bundle_hash'] ?? null,
            'external_action_ref' => $payload['reference_id'] ?? null,
            'customer_id' => $customerId,
            'provider_account_id' => $providerAccountId,
            'provider_code' => $providerCode,
            'domain_name' => strtolower($domainName),
            'tld' => $tld,
            'registrar_status' => 'pending_submission',
            'metadata_json' => json_encode([
                'period_years' => $periodYears,
                'draft_payload' => $payload,
            ], JSON_UNESCAPED_SLASHES),
        ];

        $this->database->execute(
            'INSERT INTO domains (
                id, tenant_id, owner_type, owner_id, acting_user_id, acting_persona_id, billing_mode, billing_tenant_id,
                finance_event_ref, receipt_bundle_path, receipt_bundle_hash, external_action_ref, customer_id,
                provider_account_id, provider_code, domain_name, tld, registrar_status, metadata_json, created_at, updated_at
             ) VALUES (
                :id, :tenant_id, :owner_type, :owner_id, :acting_user_id, :acting_persona_id, :billing_mode, :billing_tenant_id,
                :finance_event_ref, :receipt_bundle_path, :receipt_bundle_hash, :external_action_ref, :customer_id,
                :provider_account_id, :provider_code, :domain_name, :tld, :registrar_status, :metadata_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )',
            $record,
        );

        $this->replaceNameservers($record['id'], $nameservers);

        return $this->findById($record['id']) ?? $record;
    }

    /**
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $nameservers
     */
    public function replaceNameservers(string $domainId, array $nameservers): void
    {
        $this->database->execute(
            'DELETE FROM domain_nameservers WHERE domain_id = :domain_id',
            ['domain_id' => $domainId],
        );

        foreach ($nameservers as $index => $nameserver) {
            $hostname = trim((string) ($nameserver['hostname'] ?? ''));
            if ($hostname === '') {
                continue;
            }

            $this->database->execute(
                'INSERT INTO domain_nameservers (
                    id, domain_id, hostname, ipv4_address, ipv6_address, sort_order, created_at, updated_at
                 ) VALUES (
                    :id, :domain_id, :hostname, :ipv4_address, :ipv6_address, :sort_order, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
                 )',
                [
                    'id' => Uuid::v4(),
                    'domain_id' => $domainId,
                    'hostname' => $hostname,
                    'ipv4_address' => $nameserver['ipv4'] ?? null,
                    'ipv6_address' => $nameserver['ipv6'] ?? null,
                    'sort_order' => $index,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed> $providerResult
     */
    public function markRegistered(string $domainId, array $providerResult): void
    {
        $existing = $this->findById($domainId);
        $metadata = $this->mergeMetadata(
            $existing,
            [
                'last_registration_result' => $providerResult,
                'raw' => $providerResult['raw'] ?? null,
            ],
        );

        $this->database->execute(
            'UPDATE domains
             SET upstream_domain_id = :upstream_domain_id,
                 upstream_order_id = COALESCE(:upstream_order_id, upstream_order_id),
                 registrar_status = :registrar_status,
                 finance_event_ref = COALESCE(:finance_event_ref, finance_event_ref),
                 receipt_bundle_path = COALESCE(:receipt_bundle_path, receipt_bundle_path),
                 receipt_bundle_hash = COALESCE(:receipt_bundle_hash, receipt_bundle_hash),
                 auto_renew_enabled = COALESCE(:auto_renew_enabled, auto_renew_enabled),
                 registered_at = COALESCE(:registered_at, registered_at),
                 expires_at = COALESCE(:expires_at, expires_at),
                 renewal_due_at = COALESCE(:renewal_due_at, renewal_due_at),
                 grace_period_ends_at = COALESCE(:grace_period_ends_at, grace_period_ends_at),
                 redemption_period_ends_at = COALESCE(:redemption_period_ends_at, redemption_period_ends_at),
                 last_synced_at = CURRENT_TIMESTAMP,
                 last_sync_source = :last_sync_source,
                 last_sync_error = NULL,
                 metadata_json = :metadata_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $domainId,
                'upstream_domain_id' => $providerResult['upstream_domain_id'] ?? $providerResult['roid'] ?? $providerResult['entityid'] ?? null,
                'upstream_order_id' => $providerResult['upstream_order_id'] ?? $providerResult['orderid'] ?? null,
                'registrar_status' => $providerResult['registrar_status'] ?? 'active',
                'finance_event_ref' => $providerResult['finance_event_ref'] ?? null,
                'receipt_bundle_path' => $providerResult['receipt_bundle_path'] ?? null,
                'receipt_bundle_hash' => $providerResult['receipt_bundle_hash'] ?? null,
                'auto_renew_enabled' => $this->normalizeBoolean($providerResult['auto_renew_enabled'] ?? null),
                'registered_at' => $providerResult['registered_at'] ?? $providerResult['created_at'] ?? null,
                'expires_at' => $providerResult['expires_at'] ?? null,
                'renewal_due_at' => $providerResult['renewal_due_at'] ?? $providerResult['expires_at'] ?? null,
                'grace_period_ends_at' => $providerResult['grace_period_ends_at'] ?? null,
                'redemption_period_ends_at' => $providerResult['redemption_period_ends_at'] ?? null,
                'last_sync_source' => $providerResult['provider'] ?? 'worker',
                'metadata_json' => $metadata,
            ],
        );

        if (isset($providerResult['nameservers']) && is_array($providerResult['nameservers'])) {
            $nameservers = array_map(
                static fn (mixed $nameserver): array => is_array($nameserver)
                    ? [
                        'hostname' => (string) ($nameserver['hostname'] ?? ''),
                        'ipv4' => $nameserver['ipv4'] ?? null,
                        'ipv6' => $nameserver['ipv6'] ?? null,
                    ]
                    : ['hostname' => (string) $nameserver],
                $providerResult['nameservers'],
            );
            $this->replaceNameservers($domainId, $nameservers);
        }

        if (isset($providerResult['statuses']) && is_array($providerResult['statuses'])) {
            $this->replaceStatuses($domainId, $providerResult['statuses'], (string) ($providerResult['provider'] ?? 'worker'));
        }
    }

    public function markFailed(string $domainId, string $error): void
    {
        $this->database->execute(
            'UPDATE domains
             SET registrar_status = :registrar_status, last_sync_error = :last_sync_error, updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $domainId,
                'registrar_status' => 'submission_failed',
                'last_sync_error' => $error,
            ],
        );
    }

    public function updateFromSync(string $domainId, array $syncResult): void
    {
        $existing = $this->findById($domainId);
        $metadata = $this->mergeMetadata(
            $existing,
            [
                'last_sync_result' => $syncResult,
                'raw' => $syncResult['raw'] ?? null,
            ],
        );

        $this->database->execute(
            'UPDATE domains
             SET upstream_domain_id = COALESCE(:upstream_domain_id, upstream_domain_id),
                 upstream_order_id = COALESCE(:upstream_order_id, upstream_order_id),
                 registrar_status = COALESCE(:registrar_status, registrar_status),
                 finance_event_ref = COALESCE(:finance_event_ref, finance_event_ref),
                 receipt_bundle_path = COALESCE(:receipt_bundle_path, receipt_bundle_path),
                 receipt_bundle_hash = COALESCE(:receipt_bundle_hash, receipt_bundle_hash),
                 auto_renew_enabled = COALESCE(:auto_renew_enabled, auto_renew_enabled),
                 registered_at = COALESCE(:registered_at, registered_at),
                 expires_at = COALESCE(:expires_at, expires_at),
                 renewal_due_at = COALESCE(:renewal_due_at, renewal_due_at),
                 grace_period_ends_at = COALESCE(:grace_period_ends_at, grace_period_ends_at),
                 redemption_period_ends_at = COALESCE(:redemption_period_ends_at, redemption_period_ends_at),
                 last_synced_at = CURRENT_TIMESTAMP,
                 last_sync_source = :last_sync_source,
                 last_sync_error = NULL,
                 metadata_json = :metadata_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $domainId,
                'upstream_domain_id' => $syncResult['upstream_domain_id'] ?? null,
                'upstream_order_id' => $syncResult['upstream_order_id'] ?? $syncResult['orderid'] ?? null,
                'registrar_status' => $syncResult['registrar_status'] ?? null,
                'finance_event_ref' => $syncResult['finance_event_ref'] ?? null,
                'receipt_bundle_path' => $syncResult['receipt_bundle_path'] ?? null,
                'receipt_bundle_hash' => $syncResult['receipt_bundle_hash'] ?? null,
                'auto_renew_enabled' => $this->normalizeBoolean($syncResult['auto_renew_enabled'] ?? null),
                'registered_at' => $syncResult['registered_at'] ?? null,
                'expires_at' => $syncResult['expires_at'] ?? null,
                'renewal_due_at' => $syncResult['renewal_due_at'] ?? $syncResult['expires_at'] ?? null,
                'grace_period_ends_at' => $syncResult['grace_period_ends_at'] ?? null,
                'redemption_period_ends_at' => $syncResult['redemption_period_ends_at'] ?? null,
                'last_sync_source' => $syncResult['provider'] ?? 'worker',
                'metadata_json' => $metadata,
            ],
        );

        if (isset($syncResult['nameservers']) && is_array($syncResult['nameservers'])) {
            $nameservers = array_map(
                static fn (mixed $nameserver): array => is_array($nameserver)
                    ? [
                        'hostname' => (string) ($nameserver['hostname'] ?? ''),
                        'ipv4' => $nameserver['ipv4'] ?? null,
                        'ipv6' => $nameserver['ipv6'] ?? null,
                    ]
                    : ['hostname' => (string) $nameserver],
                $syncResult['nameservers'],
            );
            $this->replaceNameservers($domainId, $nameservers);
        }

        if (isset($syncResult['statuses']) && is_array($syncResult['statuses'])) {
            $this->replaceStatuses($domainId, $syncResult['statuses'], (string) ($syncResult['provider'] ?? 'worker'));
        }
    }

    public function findById(string $id): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM domains WHERE id = :id LIMIT 1',
            ['id' => $id],
        );
    }

    public function findByName(string $domainName): ?array
    {
        return $this->database->fetchOne(
            'SELECT * FROM domains WHERE domain_name = :domain_name LIMIT 1',
            ['domain_name' => strtolower($domainName)],
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listRecent(int $limit = 10): array
    {
        return $this->database->fetchAll(
            sprintf(
                'SELECT * FROM domains ORDER BY created_at DESC LIMIT %d',
                max(1, $limit),
            ),
        );
    }

    /**
     * @return list<array<string, mixed>>
     */
    public function listByProvider(string $providerAccountId, int $limit = 100): array
    {
        return $this->database->fetchAll(
            sprintf(
                'SELECT * FROM domains WHERE provider_account_id = :provider_account_id ORDER BY created_at DESC LIMIT %d',
                max(1, $limit),
            ),
            ['provider_account_id' => $providerAccountId],
        );
    }

    /**
     * @param list<array<string, mixed>|string> $statuses
     */
    private function replaceStatuses(string $domainId, array $statuses, string $source): void
    {
        $this->database->execute(
            'DELETE FROM domain_statuses WHERE domain_id = :domain_id',
            ['domain_id' => $domainId],
        );

        foreach ($statuses as $status) {
            $code = is_array($status) ? trim((string) ($status['code'] ?? '')) : trim((string) $status);
            if ($code === '') {
                continue;
            }

            $this->database->execute(
                'INSERT INTO domain_statuses (id, domain_id, status_code, status_label, source, observed_at)
                 VALUES (:id, :domain_id, :status_code, :status_label, :source, CURRENT_TIMESTAMP)',
                [
                    'id' => Uuid::v4(),
                    'domain_id' => $domainId,
                    'status_code' => $code,
                    'status_label' => is_array($status) ? ($status['label'] ?? null) : null,
                    'source' => $source,
                ],
            );
        }
    }

    /**
     * @param array<string, mixed>|null $domain
     * @param array<string, mixed> $patch
     */
    private function mergeMetadata(?array $domain, array $patch): ?string
    {
        $existing = [];
        if (is_array($domain) && isset($domain['metadata_json']) && is_string($domain['metadata_json'])) {
            $decoded = json_decode($domain['metadata_json'], true);
            if (is_array($decoded)) {
                $existing = $decoded;
            }
        }

        return json_encode(array_replace_recursive($existing, $patch), JSON_UNESCAPED_SLASHES);
    }

    private function normalizeBoolean(mixed $value): ?int
    {
        if ($value === null || $value === '') {
            return null;
        }

        return in_array(strtolower((string) $value), ['1', 'true', 'yes', 'on'], true) ? 1 : 0;
    }
}
