<?php

declare(strict_types=1);

namespace App\Services;

use App\Infrastructure\Database\Database;
use App\Infrastructure\Persistence\CustomerRepository;
use App\Infrastructure\Persistence\DomainRepository;
use App\Infrastructure\Persistence\OrderRepository;
use App\Infrastructure\Persistence\ProviderAccountRepository;
use App\Infrastructure\Providers\NetEarthOneProvider;
use App\Infrastructure\Persistence\TaskQueueRepository;
use App\Infrastructure\Providers\CoZaProvider;
use InvalidArgumentException;
use App\Support\Uuid;

final class OrderService
{
    public function __construct(
        private readonly Database $database,
        private readonly CustomerRepository $customerRepository,
        private readonly ProviderAccountRepository $providerAccountRepository,
        private readonly DomainRepository $domainRepository,
        private readonly OrderRepository $orderRepository,
        private readonly TaskQueueRepository $taskQueueRepository,
    ) {
    }

    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function createRegistrationOrder(array $payload, bool $queueLiveSubmission): array
    {
        $domainName = strtolower(trim((string) ($payload['domain_name'] ?? '')));
        $providerCode = $this->resolveProviderCode((string) ($payload['provider_code'] ?? ''), $domainName);
        $email = strtolower(trim((string) ($payload['customer_email'] ?? '')));
        $registrant = trim((string) ($payload['registrant'] ?? ''));
        $authInfo = trim((string) ($payload['auth_info'] ?? ''));
        $customerId = trim((string) ($payload['customer_id'] ?? ''));
        $adminContact = trim((string) (($payload['contacts']['admin'] ?? '')));
        $techContact = trim((string) (($payload['contacts']['tech'] ?? '')));
        $billingContact = trim((string) (($payload['contacts']['billing'] ?? '')));
        $nameservers = array_values(array_filter(
            is_array($payload['nameservers'] ?? null) ? $payload['nameservers'] : [],
            static fn (mixed $nameserver): bool => is_array($nameserver) && trim((string) ($nameserver['hostname'] ?? '')) !== '',
        ));

        if ($domainName === '' || $email === '' || $registrant === '') {
            throw new InvalidArgumentException('Domain, customer email, and registrant handle are required.');
        }

        if ($providerCode === 'coza' && $authInfo === '') {
            throw new InvalidArgumentException('.co.za registration requires auth info.');
        }

        if ($providerCode === 'netearthone') {
            if ($customerId === '' || $adminContact === '' || $techContact === '' || $billingContact === '') {
                throw new InvalidArgumentException('NetEarthOne registration requires customer, admin, tech, and billing contact IDs.');
            }

            if (count($nameservers) < 2) {
                throw new InvalidArgumentException('NetEarthOne registration requires at least two nameservers.');
            }
        }

        $providerAccount = $this->providerAccountRepository->getOrCreate(
            $providerCode,
            $this->providerDisplayName($providerCode),
            $this->providerDriverClass($providerCode),
        );

        $tenantId = trim((string) ($payload['tenant_id'] ?? 'user:local-demo'));
        $ownerType = trim((string) ($payload['owner_type'] ?? $this->inferOwnerType($tenantId)));
        $ownerId = trim((string) ($payload['owner_id'] ?? $tenantId));
        $actingUserId = $this->nullableString($payload['acting_user_id'] ?? null);
        $actingPersonaId = $this->nullableString($payload['acting_persona_id'] ?? null);
        $billingMode = trim((string) ($payload['billing_mode'] ?? ($ownerType === 'company' ? 'company' : 'user')));
        $billingTenantId = trim((string) ($payload['billing_tenant_id'] ?? $tenantId));
        $tenantDbConfigId = $this->nullableString($payload['tenant_db_config_id'] ?? null);
        $referenceId = trim((string) ($payload['reference_id'] ?? sprintf('register:%s', Uuid::v4())));

        $enrichedPayload = array_replace($payload, [
            'tenant_id' => $tenantId,
            'tenant_db_config_id' => $tenantDbConfigId,
            'owner_type' => $ownerType,
            'owner_id' => $ownerId,
            'acting_user_id' => $actingUserId,
            'acting_persona_id' => $actingPersonaId,
            'billing_mode' => $billingMode,
            'billing_tenant_id' => $billingTenantId,
            'reference_id' => $referenceId,
        ]);

        $result = $this->database->transaction(function () use ($enrichedPayload, $providerAccount, $providerCode, $queueLiveSubmission): array {
            $customer = $this->customerRepository->findOrCreateByEmail([
                'tenant_id' => $enrichedPayload['tenant_id'],
                'owner_type' => $enrichedPayload['owner_type'],
                'owner_id' => $enrichedPayload['owner_id'],
                'platform_user_id' => $this->resolvePlatformUserId($enrichedPayload),
                'platform_company_id' => $enrichedPayload['owner_type'] === 'company' ? $enrichedPayload['owner_id'] : null,
                'platform_persona_id' => $this->resolvePlatformPersonaId($enrichedPayload),
                'email' => $enrichedPayload['customer_email'] ?? '',
                'first_name' => $enrichedPayload['customer_first_name'] ?? '',
                'last_name' => $enrichedPayload['customer_last_name'] ?? '',
                'company_name' => $enrichedPayload['customer_company_name'] ?? '',
            ]);

            $domain = $this->domainRepository->createDraft(
                $providerAccount['id'],
                $providerCode,
                $customer['id'],
                (string) $enrichedPayload['domain_name'],
                (int) ($enrichedPayload['period_years'] ?? 1),
                $enrichedPayload,
                $enrichedPayload['nameservers'] ?? [],
            );

            $order = $this->orderRepository->createRegistrationOrder(
                $customer['id'],
                $providerAccount['id'],
                $providerCode,
                $domain['id'],
                (string) $enrichedPayload['customer_email'],
                (int) ($enrichedPayload['period_years'] ?? 1),
                $queueLiveSubmission ? 'live' : 'draft',
                $enrichedPayload,
                95.00 * (int) ($enrichedPayload['period_years'] ?? 1),
            );

            return [
                'customer' => $customer,
                'domain' => $domain,
                'order' => $order,
            ];
        });

        $task = null;
        if ($queueLiveSubmission) {
            $task = $this->taskQueueRepository->enqueue(
                'submit_domain_registration',
                'orders',
                [
                    'order_id' => $result['order']['id'],
                    'domain_id' => $result['domain']['id'],
                    'provider_code' => $providerCode,
                    'tenant_id' => $tenantId,
                    'tenant_db_config_id' => $tenantDbConfigId,
                    'owner_type' => $ownerType,
                    'owner_id' => $ownerId,
                    'acting_user_id' => $actingUserId,
                    'acting_persona_id' => $actingPersonaId,
                    'billing_mode' => $billingMode,
                    'billing_tenant_id' => $billingTenantId,
                    'reference_id' => $referenceId,
                ],
                priority: 100,
                maxAttempts: 3,
            );

            if ($task !== null) {
                $this->orderRepository->markQueued((string) $result['order']['id']);
                $result['order'] = $this->orderRepository->findById((string) $result['order']['id']) ?? $result['order'];
            }
        }

        return $result + ['task' => $task];
    }

    private function resolveProviderCode(string $providerCode, string $domainName): string
    {
        $providerCode = strtolower(trim($providerCode));
        if ($providerCode !== '') {
            return $providerCode;
        }

        return str_ends_with($domainName, '.za') ? 'coza' : 'netearthone';
    }

    private function providerDisplayName(string $providerCode): string
    {
        return match ($providerCode) {
            'coza' => '.co.za',
            'netearthone' => 'NetEarthOne',
            default => ucfirst($providerCode),
        };
    }

    private function providerDriverClass(string $providerCode): string
    {
        return match ($providerCode) {
            'coza' => CoZaProvider::class,
            'netearthone' => NetEarthOneProvider::class,
            default => throw new InvalidArgumentException(sprintf('Unsupported provider "%s".', $providerCode)),
        };
    }

    private function inferOwnerType(string $tenantId): string
    {
        return match (true) {
            str_starts_with($tenantId, 'company:') => 'company',
            str_starts_with($tenantId, 'persona:') => 'persona',
            default => 'user',
        };
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePlatformUserId(array $payload): ?string
    {
        if (($payload['owner_type'] ?? null) === 'user') {
            return (string) ($payload['owner_id'] ?? '');
        }

        return $this->nullableString($payload['acting_user_id'] ?? null);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function resolvePlatformPersonaId(array $payload): ?string
    {
        if (($payload['owner_type'] ?? null) === 'persona') {
            return (string) ($payload['owner_id'] ?? '');
        }

        return $this->nullableString($payload['acting_persona_id'] ?? null);
    }

    private function nullableString(mixed $value): ?string
    {
        if (! is_string($value)) {
            return null;
        }

        $trimmed = trim($value);

        return $trimmed === '' ? null : $trimmed;
    }
}
