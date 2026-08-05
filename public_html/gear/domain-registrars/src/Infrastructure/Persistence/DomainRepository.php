<?php

declare(strict_types=1);

namespace App\Infrastructure\Persistence;

use App\Infrastructure\Database\Database;
use App\Support\Uuid;

final class DomainRepository
{
    /** @var array<string, list<string>>|null */
    private ?array $columnCache = null;

    public function __construct(
        private readonly Database $database,
    ) {
    }

    /**
     * @return list<string>
     */
    private function domainsColumns(): array
    {
        if (is_array($this->columnCache)) {
            return $this->columnCache;
        }

        try {
            $rows = $this->database->fetchAll('SHOW COLUMNS FROM domains');
        } catch (\Throwable) {
            $rows = false;
        }

        $columns = [];
        if (is_array($rows)) {
            foreach ($rows as $row) {
                $name = is_array($row) ? (string) ($row['Field'] ?? $row[0] ?? '') : (string) $row;
                if ($name !== '') {
                    $columns[] = $name;
                }
            }
        }

        $columns = array_values(array_unique($columns));
        $this->columnCache = $columns;

        return $columns;
    }

    private function columnExists(string $column): bool
    {
        return in_array($column, $this->domainsColumns(), true);
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
                'registrant' => $providerResult['registrant'] ?? null,
                'contacts' => is_array($providerResult['contacts'] ?? null) ? $providerResult['contacts'] : [],
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
                    'registered_at' => $this->nullableTimestampString($providerResult['registered_at'] ?? $providerResult['created_at'] ?? null),
                    'expires_at' => $this->nullableTimestampString($providerResult['expires_at'] ?? null),
                    'renewal_due_at' => $this->nullableTimestampString($providerResult['renewal_due_at'] ?? $providerResult['expires_at'] ?? null),
                    'grace_period_ends_at' => $this->nullableTimestampString($providerResult['grace_period_ends_at'] ?? null),
                    'redemption_period_ends_at' => $this->nullableTimestampString($providerResult['redemption_period_ends_at'] ?? null),
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
        $contacts = is_array($syncResult['contacts'] ?? null) ? $syncResult['contacts'] : [];
        $metadata = $this->mergeMetadata(
            $existing,
            [
                'last_sync_result' => $syncResult,
                'registrant' => $syncResult['registrant'] ?? null,
                'contacts' => $contacts,
                'raw' => $syncResult['raw'] ?? null,
            ],
        );

        $set = [
            'upstream_domain_id = COALESCE(:upstream_domain_id, upstream_domain_id)',
            'upstream_order_id = COALESCE(:upstream_order_id, upstream_order_id)',
            'registrar_status = COALESCE(:registrar_status, registrar_status)',
            'finance_event_ref = COALESCE(:finance_event_ref, finance_event_ref)',
            'receipt_bundle_path = COALESCE(:receipt_bundle_path, receipt_bundle_path)',
            'receipt_bundle_hash = COALESCE(:receipt_bundle_hash, receipt_bundle_hash)',
            'auto_renew_enabled = COALESCE(:auto_renew_enabled, auto_renew_enabled)',
            'registered_at = COALESCE(:registered_at, registered_at)',
            'expires_at = COALESCE(:expires_at, expires_at)',
            'renewal_due_at = COALESCE(:renewal_due_at, renewal_due_at)',
            'grace_period_ends_at = COALESCE(:grace_period_ends_at, grace_period_ends_at)',
            'redemption_period_ends_at = COALESCE(:redemption_period_ends_at, redemption_period_ends_at)',
            'last_synced_at = CURRENT_TIMESTAMP',
            'last_sync_source = :last_sync_source',
            'last_sync_error = NULL',
            'metadata_json = :metadata_json',
            'updated_at = CURRENT_TIMESTAMP',
        ];

        $params = [
            'id' => $domainId,
            'upstream_domain_id' => $syncResult['upstream_domain_id'] ?? null,
            'upstream_order_id' => $syncResult['upstream_order_id'] ?? $syncResult['orderid'] ?? null,
            'registrar_status' => $syncResult['registrar_status'] ?? null,
            'finance_event_ref' => $syncResult['finance_event_ref'] ?? null,
            'receipt_bundle_path' => $syncResult['receipt_bundle_path'] ?? null,
            'receipt_bundle_hash' => $syncResult['receipt_bundle_hash'] ?? null,
            'auto_renew_enabled' => $this->normalizeBoolean($syncResult['auto_renew_enabled'] ?? null),
            'registered_at' => $this->nullableTimestampString($syncResult['registered_at'] ?? null),
            'expires_at' => $this->nullableTimestampString($syncResult['expires_at'] ?? null),
            'renewal_due_at' => $this->nullableTimestampString($syncResult['renewal_due_at'] ?? $syncResult['expires_at'] ?? null),
            'grace_period_ends_at' => $this->nullableTimestampString($syncResult['grace_period_ends_at'] ?? null),
            'redemption_period_ends_at' => $this->nullableTimestampString($syncResult['redemption_period_ends_at'] ?? null),
            'last_sync_source' => $syncResult['provider'] ?? 'worker',
            'metadata_json' => $metadata,
        ];

        $registrantValue = $syncResult['registrant'] ?? null;
        $adminValue = is_string($contacts['admin'] ?? null) ? (string) $contacts['admin'] : null;
        $techValue = is_string($contacts['tech'] ?? null) ? (string) $contacts['tech'] : null;
        $billingValue = is_string($contacts['billing'] ?? null) ? (string) $contacts['billing'] : null;

        if ($this->columnExists('registrant_handle')) {
            $set[] = 'registrant_handle = COALESCE(:registrant_handle, registrant_handle)';
            $params['registrant_handle'] = $registrantValue;
        } elseif ($this->columnExists('registrant')) {
            $set[] = 'registrant = COALESCE(:registrant_legacy, registrant)';
            $params['registrant_legacy'] = $registrantValue;
        }

        if ($this->columnExists('admin_handle')) {
            $set[] = 'admin_handle = COALESCE(:admin_handle, admin_handle)';
            $params['admin_handle'] = $adminValue;
        } elseif ($this->columnExists('admin')) {
            $set[] = 'admin = COALESCE(:admin_legacy, admin)';
            $params['admin_legacy'] = $adminValue;
        }

        if ($this->columnExists('tech_handle')) {
            $set[] = 'tech_handle = COALESCE(:tech_handle, tech_handle)';
            $params['tech_handle'] = $techValue;
        } elseif ($this->columnExists('tech')) {
            $set[] = 'tech = COALESCE(:tech_legacy, tech)';
            $params['tech_legacy'] = $techValue;
        }

        if ($this->columnExists('billing_handle')) {
            $set[] = 'billing_handle = COALESCE(:billing_handle, billing_handle)';
            $params['billing_handle'] = $billingValue;
        } elseif ($this->columnExists('billing')) {
            $set[] = 'billing = COALESCE(:billing_legacy, billing)';
            $params['billing_legacy'] = $billingValue;
        }

        $sql = 'UPDATE domains SET ' . implode(', ', $set) . ' WHERE id = :id';
        $this->database->execute($sql, $params);

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

    public function findForAccountByName(string $tenantId, string $ownerType, string $ownerId, string $domainName): ?array
    {
        return $this->database->fetchOne(
            'SELECT *
             FROM domains
             WHERE domain_name = :domain_name
               AND (
                    tenant_id = :tenant_id_filter
                    OR (owner_type = :owner_type AND owner_id = :owner_id)
               )
             ORDER BY
                CASE WHEN tenant_id = :tenant_id_sort THEN 0 ELSE 1 END,
                updated_at DESC,
                created_at DESC
             LIMIT 1',
            [
                'domain_name' => strtolower($domainName),
                'tenant_id_filter' => $tenantId,
                'tenant_id_sort' => $tenantId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ],
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
     * @return list<array<string, mixed>>
     */
    public function listForAccount(string $tenantId, string $ownerType, string $ownerId, int $limit = 100): array
    {
        return $this->database->fetchAll(
            sprintf(
                'SELECT *
                 FROM domains
                 WHERE tenant_id = :tenant_id
                    OR (owner_type = :owner_type AND owner_id = :owner_id)
                 ORDER BY COALESCE(expires_at, renewal_due_at, created_at) ASC
                 LIMIT %d',
                max(1, $limit),
            ),
            [
                'tenant_id' => $tenantId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
            ],
        );
    }

    /**
     * @return list<array{hostname: string, ipv4_address: string|null, ipv6_address: string|null, sort_order: int}>
     */
    public function listNameservers(string $domainId): array
    {
        return $this->database->fetchAll(
            'SELECT hostname, ipv4_address, ipv6_address, sort_order
             FROM domain_nameservers
             WHERE domain_id = :domain_id
             ORDER BY sort_order ASC, created_at ASC',
            ['domain_id' => $domainId],
        );
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function assignOwnership(string $domainId, array $payload): ?array
    {
        $existing = $this->findById($domainId);
        if ($existing === null) {
            return null;
        }

        $tenantId = trim((string) ($payload['tenant_id'] ?? ($existing['tenant_id'] ?? '')));
        $ownerType = trim((string) ($payload['owner_type'] ?? ($existing['owner_type'] ?? 'user')));
        $ownerId = trim((string) ($payload['owner_id'] ?? ($existing['owner_id'] ?? $tenantId)));
        $billingMode = trim((string) ($payload['billing_mode'] ?? ($existing['billing_mode'] ?? 'user')));
        $billingTenantId = trim((string) ($payload['billing_tenant_id'] ?? ($existing['billing_tenant_id'] ?? $tenantId)));
        $customerId = trim((string) ($payload['customer_id'] ?? ''));
        $metadata = $this->mergeMetadata(
            $existing,
            [
                'allocation' => [
                    'tenant_id' => $tenantId,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'billing_mode' => $billingMode,
                    'billing_tenant_id' => $billingTenantId,
                ],
            ],
        );

        $this->database->execute(
            'UPDATE domains
             SET tenant_id = :tenant_id,
                 owner_type = :owner_type,
                 owner_id = :owner_id,
                 billing_mode = :billing_mode,
                 billing_tenant_id = :billing_tenant_id,
                 customer_id = COALESCE(:customer_id, customer_id),
                 metadata_json = :metadata_json,
                 updated_at = CURRENT_TIMESTAMP
             WHERE id = :id',
            [
                'id' => $domainId,
                'tenant_id' => $tenantId,
                'owner_type' => $ownerType,
                'owner_id' => $ownerId,
                'billing_mode' => $billingMode,
                'billing_tenant_id' => $billingTenantId,
                'customer_id' => $customerId === '' ? null : $customerId,
                'metadata_json' => $metadata,
            ],
        );

        return $this->findById($domainId);
    }

    /**
     * @param array<string, mixed> $payload
     */
    public function upsertImportedDomain(
        string $providerAccountId,
        string $providerCode,
        string $domainName,
        array $payload = [],
    ): array {
        $normalizedDomain = strtolower(trim($domainName));
        if ($normalizedDomain === '') {
            throw new \InvalidArgumentException('Domain name is required for imports.');
        }

        $existing = $this->database->fetchOne(
            'SELECT * FROM domains WHERE provider_account_id = :provider_account_id AND domain_name = :domain_name LIMIT 1',
            [
                'provider_account_id' => $providerAccountId,
                'domain_name' => $normalizedDomain,
            ],
        );

        $tenantId = trim((string) ($payload['tenant_id'] ?? ''));
        $ownerType = trim((string) ($payload['owner_type'] ?? 'user'));
        $ownerId = trim((string) ($payload['owner_id'] ?? ''));
        $billingTenantId = trim((string) ($payload['billing_tenant_id'] ?? $tenantId));
        $tld = str_contains($normalizedDomain, '.') ? substr($normalizedDomain, strpos($normalizedDomain, '.') + 1) : $normalizedDomain;

        $metadataPatch = [
            'import' => [
                'source' => 'control-bulk-import',
                'imported_domain_name' => $normalizedDomain,
                'autorenew' => $payload['autorenew'] ?? null,
                'registrant' => $payload['registrant'] ?? null,
                'billing' => $payload['billing'] ?? null,
                'admin' => $payload['admin'] ?? null,
                'tech' => $payload['tech'] ?? null,
            ],
        ];
        $registeredAt = $this->nullableTimestampString($payload['registered_at'] ?? $payload['cdate'] ?? null);
        $expiresAt = $this->nullableTimestampString($payload['expires_at'] ?? $payload['expiry'] ?? null);
        $autoRenewEnabled = $this->normalizeBoolean($payload['autorenew'] ?? $payload['auto_renew_enabled'] ?? false) ?? 0;

        if ($existing !== null) {
            $metadata = $this->mergeMetadata($existing, $metadataPatch);
            $this->database->execute(
                'UPDATE domains
                 SET tenant_id = :tenant_id,
                     owner_type = :owner_type,
                     owner_id = :owner_id,
                     billing_mode = :billing_mode,
                     billing_tenant_id = :billing_tenant_id,
                     provider_code = :provider_code,
                     registrar_status = :registrar_status,
                     auto_renew_enabled = :auto_renew_enabled,
                     registered_at = :registered_at,
                     expires_at = :expires_at,
                     renewal_due_at = :renewal_due_at,
                     last_sync_error = NULL,
                     metadata_json = :metadata_json,
                     updated_at = CURRENT_TIMESTAMP
                 WHERE id = :id',
                [
                    'id' => $existing['id'],
                    'tenant_id' => $tenantId,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'billing_mode' => (string) ($payload['billing_mode'] ?? 'user'),
                    'billing_tenant_id' => $billingTenantId,
                    'provider_code' => $providerCode,
                    'registrar_status' => (string) ($payload['registrar_status'] ?? ($existing['registrar_status'] ?? 'imported')),
                    'auto_renew_enabled' => $autoRenewEnabled,
                    'registered_at' => $registeredAt,
                    'expires_at' => $expiresAt,
                    'renewal_due_at' => $expiresAt,
                    'metadata_json' => $metadata,
                ],
            );

            return $this->findById((string) $existing['id']) ?? $existing;
        }

        $record = [
            'id' => Uuid::v4(),
            'tenant_id' => $tenantId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'acting_user_id' => $payload['acting_user_id'] ?? null,
            'acting_persona_id' => $payload['acting_persona_id'] ?? null,
            'billing_mode' => (string) ($payload['billing_mode'] ?? 'user'),
            'billing_tenant_id' => $billingTenantId,
            'finance_event_ref' => null,
            'receipt_bundle_path' => null,
            'receipt_bundle_hash' => null,
            'external_action_ref' => null,
            'customer_id' => null,
            'provider_account_id' => $providerAccountId,
            'provider_code' => $providerCode,
            'domain_name' => $normalizedDomain,
            'tld' => $tld,
            'registrar_status' => (string) ($payload['registrar_status'] ?? 'active'),
            'auto_renew_enabled' => $autoRenewEnabled,
            'registered_at' => $registeredAt,
            'expires_at' => $expiresAt,
            'renewal_due_at' => $expiresAt,
            'metadata_json' => json_encode($metadataPatch, JSON_UNESCAPED_SLASHES),
        ];

        $this->database->execute(
            'INSERT INTO domains (
                id, tenant_id, owner_type, owner_id, acting_user_id, acting_persona_id, billing_mode, billing_tenant_id,
                finance_event_ref, receipt_bundle_path, receipt_bundle_hash, external_action_ref, customer_id,
                provider_account_id, provider_code, domain_name, tld, registrar_status, auto_renew_enabled,
                registered_at, expires_at, renewal_due_at, metadata_json, created_at, updated_at
             ) VALUES (
                :id, :tenant_id, :owner_type, :owner_id, :acting_user_id, :acting_persona_id, :billing_mode, :billing_tenant_id,
                :finance_event_ref, :receipt_bundle_path, :receipt_bundle_hash, :external_action_ref, :customer_id,
                :provider_account_id, :provider_code, :domain_name, :tld, :registrar_status, :auto_renew_enabled,
                :registered_at, :expires_at, :renewal_due_at, :metadata_json, CURRENT_TIMESTAMP, CURRENT_TIMESTAMP
             )',
            $record,
        );

        return $this->findById($record['id']) ?? $record;
    }

    private function nullableTimestampString(mixed $value): ?string
    {
        if (! is_string($value) && ! is_numeric($value)) {
            return null;
        }

        $normalized = trim((string) $value);
        if ($normalized === '') {
            return null;
        }

        try {
            $timestamp = new \DateTimeImmutable($normalized);

            return $timestamp->format('Y-m-d H:i:s');
        } catch (\Throwable) {
            return $normalized;
        }
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
