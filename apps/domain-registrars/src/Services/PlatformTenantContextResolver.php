<?php

declare(strict_types=1);

namespace App\Services;

use App\Config\AppConfig;

final class PlatformTenantContextResolver
{
    public function __construct(
        private readonly AppConfig $config,
    ) {
    }

    /**
     * @return array<string, string|null>
     */
    public function resolve(): array
    {
        $tenantId = $this->resolveTenantId();
        $ownerType = $this->config->nullableString('DOMAIN_OWNER_TYPE')
            ?? $this->sessionValue('mh_owner_type')
            ?? $this->ownerTypeFromTenant($tenantId);
        $ownerId = $this->config->nullableString('DOMAIN_OWNER_ID')
            ?? $this->sessionValue('mh_owner_id')
            ?? $tenantId;
        $billingMode = $this->config->nullableString('DOMAIN_BILLING_MODE')
            ?? $this->sessionValue('mh_billing_mode')
            ?? ($ownerType === 'company' ? 'company' : 'user');
        $billingTenantId = $this->normalizeTenantId(
            $this->config->nullableString('DOMAIN_BILLING_TENANT_ID')
                ?? $this->sessionValue('mh_billing_tenant_id')
                ?? ($ownerType === 'company' ? $ownerId : $tenantId),
        );

        return [
            'tenant_id' => $tenantId,
            'tenant_db_config_id' => $this->resolveTenantDbConfigId($tenantId),
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'acting_user_id' => $this->normalizeUserId(
                $this->sessionValue('mh_acting_user_id')
                    ?? $this->sessionValue('mh_auth_user')
                    ?? $this->config->nullableString('MH_AUTH_USER'),
            ),
            'acting_persona_id' => $this->normalizePersonaId(
                $this->sessionValue('mh_persona_id')
                    ?? $this->sessionValue('mh_persona_tenant_id')
                    ?? $this->config->nullableString('MH_PERSONA_ID'),
            ),
            'billing_mode' => $billingMode,
            'billing_tenant_id' => $billingTenantId,
            'company_name' => $this->sessionValue('mh_company_name'),
            'company_registration_number' => $this->sessionValue('mh_company_registration_number'),
            'context_source' => $this->sessionValue('mh_auth_user') !== null ? 'platform_session' : 'local_fallback',
        ];
    }

    private function resolveTenantId(): string
    {
        return $this->normalizeTenantId(
            $this->sessionValue('mh_tenant_id')
                ?? $this->config->nullableString('TENANT_ID')
                ?? 'user:local-demo',
        );
    }

    private function resolveTenantDbConfigId(string $tenantId): ?string
    {
        $sessionConfigId = $this->sessionValue('mh_db_preference')
            ?? $this->sessionValue('current_database_config_id')
            ?? $this->config->nullableString('TENANT_DB_CONFIG_ID');

        if ($sessionConfigId !== null) {
            return $sessionConfigId;
        }

        if (function_exists('mh_control_plane_resolve_db_config_id')) {
            $configId = mh_control_plane_resolve_db_config_id($tenantId);
            if (is_string($configId) && $configId !== '') {
                return $configId;
            }
        }

        if (function_exists('mh_resolve_tenant_db_config_id')) {
            $configId = mh_resolve_tenant_db_config_id($tenantId);
            if (is_string($configId) && $configId !== '') {
                return $configId;
            }
        }

        if (function_exists('database_getContextAwareConfiguration')) {
            try {
                $config = database_getContextAwareConfiguration(null);
                $configId = is_array($config) ? ($config['id'] ?? null) : null;
                if (is_string($configId) && $configId !== '') {
                    return $configId;
                }
            } catch (\Throwable) {
            }
        }

        return null;
    }

    private function ownerTypeFromTenant(string $tenantId): string
    {
        return match (true) {
            str_starts_with($tenantId, 'company:') => 'company',
            str_starts_with($tenantId, 'persona:') => 'persona',
            default => 'user',
        };
    }

    private function normalizeTenantId(string $tenantId): string
    {
        $tenantId = trim($tenantId);
        if ($tenantId === '') {
            return 'user:local-demo';
        }

        if (function_exists('mh_normalize_tenant_id')) {
            $normalized = mh_normalize_tenant_id($tenantId);
            if (is_string($normalized) && $normalized !== '') {
                return $normalized;
            }
        }

        if (str_starts_with($tenantId, 'user:')) {
            return 'user:' . strtolower(trim(substr($tenantId, 5)));
        }

        return $tenantId;
    }

    private function normalizeUserId(?string $userId): ?string
    {
        if ($userId === null) {
            return null;
        }

        $userId = trim($userId);
        if ($userId === '') {
            return null;
        }

        return str_starts_with($userId, 'user:') ? $userId : 'user:' . $userId;
    }

    private function normalizePersonaId(?string $personaId): ?string
    {
        if ($personaId === null) {
            return null;
        }

        $personaId = trim($personaId);
        if ($personaId === '') {
            return null;
        }

        if (str_starts_with($personaId, 'persona:')) {
            return $personaId;
        }

        return 'persona:' . $personaId;
    }

    private function sessionValue(string $key): ?string
    {
        $value = $_SESSION[$key] ?? null;

        return is_string($value) && trim($value) !== '' ? trim($value) : null;
    }
}
