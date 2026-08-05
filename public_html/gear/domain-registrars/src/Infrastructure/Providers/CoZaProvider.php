<?php

declare(strict_types=1);

namespace App\Infrastructure\Providers;

use App\Domain\Provider\Contracts\AvailabilityCheckInterface;
use App\Domain\Provider\Contracts\DomainMutationInterface;
use App\Domain\Provider\Contracts\DomainPortfolioSyncInterface;
use App\Domain\Provider\Contracts\PricingSyncInterface;
use App\Domain\Provider\Contracts\RegistrarProviderInterface;
use App\Domain\Provider\ProviderCapability;
use App\Domain\Sync\SyncContext;
use App\Infrastructure\Epp\EppClient;
use RuntimeException;

final class CoZaProvider implements RegistrarProviderInterface, DomainPortfolioSyncInterface, PricingSyncInterface, DomainMutationInterface, AvailabilityCheckInterface
{
    public function __construct(
        private readonly EppClient $client,
        private readonly string $defaultPricingPath,
        private readonly ?string $configuredPricingPath = null,
    ) {
    }

    public function code(): string
    {
        return 'coza';
    }

    public function displayName(): string
    {
        return '.co.za';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::CHECK_AVAILABILITY,
            ProviderCapability::LIST_DOMAINS,
            ProviderCapability::IMPORT_DOMAINS,
            ProviderCapability::SYNC_DOMAIN,
            ProviderCapability::SYNC_PRICING,
            ProviderCapability::REGISTER_DOMAIN,
            ProviderCapability::RENEW_DOMAIN,
            ProviderCapability::UPDATE_NAMESERVERS,
            ProviderCapability::POLL_MESSAGES,
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function healthCheck(): array
    {
        try {
            $hello = $this->client->hello();

            return [
                'ok' => true,
                'message' => 'Connected to the .co.za EPP endpoint successfully.',
                'metadata' => $hello['greeting'],
            ];
        } catch (\Throwable $exception) {
            return [
                'ok' => false,
                'message' => $exception->getMessage(),
                'metadata' => [
                    'provider' => $this->code(),
                ],
            ];
        }
    }

    public function listDomains(SyncContext $context): iterable
    {
        unset($context);

        throw new RuntimeException(
            '.co.za portfolio listing is not available through standard EPP. Seed imports from known domains, registry exports, or a registry-specific extension.',
        );
    }

    public function checkAvailability(string $domainName, SyncContext $context): array
    {
        unset($context);

        return $this->client->checkDomain($domainName);
    }

    public function fetchDomain(string $domainName, SyncContext $context): array
    {
        unset($context);

        return $this->client->domainInfo($domainName);
    }

    public function syncDomain(string $domainName, SyncContext $context): array
    {
        unset($context);

        $domain = $this->client->domainInfo($domainName);
        if (! ($domain['ok'] ?? false)) {
            return $domain;
        }

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => $domain['domain_name'] ?? $domainName,
            'upstream_domain_id' => $domain['roid'] ?? null,
            'registrar_status' => 'active',
            'expires_at' => $domain['expires_at'] ?? null,
            'registered_at' => $domain['created_at'] ?? null,
            'registrant' => $domain['registrant'] ?? null,
            'contacts' => is_array($domain['contacts'] ?? null) ? $domain['contacts'] : [],
            'nameservers' => $domain['nameservers'] ?? [],
            'statuses' => $domain['statuses'] ?? [],
            'raw' => $domain,
        ];
    }

    public function syncPricing(SyncContext $context): iterable
    {
        unset($context);

        $pricingPath = $this->configuredPricingPath ?: $this->defaultPricingPath;
        if (! is_file($pricingPath)) {
            return [];
        }

        $json = file_get_contents($pricingPath);
        if ($json === false) {
            return [];
        }

        $decoded = json_decode($json, true);
        if (! is_array($decoded)) {
            return [];
        }

        return array_values(array_filter($decoded, static fn (mixed $item): bool => is_array($item)));
    }

    public function registerDomain(array $payload): array
    {
        return $this->client->createDomain($payload);
    }

    public function renewDomain(string $domainName, int $periodYears, array $options = []): array
    {
        return $this->client->renewDomain($domainName, $periodYears, $options);
    }

    /**
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $nameservers
     * @param array{auth_info?: string|null} $options
     * @return array<string, mixed>
     */
    public function updateNameservers(string $domainName, array $nameservers, array $options = []): array
    {
        return $this->client->updateNameservers($domainName, $nameservers, $options);
    }

    /**
     * @param array{registrant?: string|null, admin?: string|null, tech?: string|null, billing?: string|null} $contacts
     * @return array<string, mixed>
     */
    public function updateContacts(string $domainName, array $contacts): array
    {
        return $this->client->updateContacts($domainName, $contacts);
    }
}
