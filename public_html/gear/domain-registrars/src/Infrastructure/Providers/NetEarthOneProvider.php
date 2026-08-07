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
use RuntimeException;
use Throwable;

final class NetEarthOneProvider implements RegistrarProviderInterface, DomainPortfolioSyncInterface, PricingSyncInterface, DomainMutationInterface, AvailabilityCheckInterface
{
    public function __construct(
        private readonly NetEarthOneApiClient $client,
        private readonly string $defaultPricingPath,
        private readonly ?string $configuredPricingPath = null,
        private readonly ?string $defaultCustomerId = null,
        private readonly string $defaultInvoiceOption = 'NoInvoice',
    ) {
    }

    public function code(): string
    {
        return 'netearthone';
    }

    public function displayName(): string
    {
        return 'NetEarthOne';
    }

    public function capabilities(): array
    {
        return [
            ProviderCapability::LIST_DOMAINS,
            ProviderCapability::IMPORT_DOMAINS,
            ProviderCapability::SYNC_DOMAIN,
            ProviderCapability::SYNC_PRICING,
            ProviderCapability::CHECK_AVAILABILITY,
            ProviderCapability::REGISTER_DOMAIN,
            ProviderCapability::RENEW_DOMAIN,
            ProviderCapability::TRANSFER_DOMAIN,
            ProviderCapability::UPDATE_NAMESERVERS,
            ProviderCapability::UPDATE_CONTACTS,
            ProviderCapability::GET_AUTH_CODE,
        ];
    }

    public function supports(string $capability): bool
    {
        return in_array($capability, $this->capabilities(), true);
    }

    public function healthCheck(): array
    {
        try {
            $authId = trim((string) $this->client->getAuthUserId());
            $altAuthId = $this->authUserIdOrDefault();
            if ($authId === '' && $altAuthId !== '') {
                $authId = $altAuthId;
            }
            if ($authId !== '' && ctype_digit($authId)) {
                $ping = $this->client->get('customers/details-by-id.json', [
                    'customer-id' => $authId,
                ]);
                if (is_array($ping) && (isset($ping['customerid']) || isset($ping['resellerid']) || isset($ping['loginUserName']) || isset($ping['customercontactid']))) {
                    return [
                        'ok' => true,
                        'status' => 'Connected to the NetEarthOne API successfully.',
                        'raw_response' => json_encode($ping, JSON_UNESCAPED_SLASHES),
                        'used_auth_id_prefix_suffix' => $this->client->authUserIdPrefixSuffix(),
                        'used_api_key_prefix_suffix' => $this->client->apiKeyPrefixSuffix(),
                    ];
                }
                $fail = is_array($ping) ? $ping : null;
            }
            $empty = $this->client->get('domains/available.json', [
                'domain-name' => ['metahumans-healthcheck-invalid'],
                'tlds' => ['com'],
            ]);

            return [
                'ok' => is_array($empty),
                'status' => is_array($empty)
                    ? 'Connected to the NetEarthOne API successfully.'
                    : 'NetEarthOne API responded but without the expected payload shape.',
                'raw_response' => json_encode(($fail ?? null) ?? $empty, JSON_UNESCAPED_SLASHES),
                'used_auth_id_prefix_suffix' => $this->client->authUserIdPrefixSuffix(),
                'used_api_key_prefix_suffix' => $this->client->apiKeyPrefixSuffix(),
            ];
        } catch (Throwable $exception) {
            $prev = $exception->getPrevious();
            $rawBody = '';
            if (is_object($prev) && method_exists($prev, 'getResponseBody')) {
                $b = $prev->getResponseBody();
                if (is_string($b) && trim($b) !== '') {
                    $rawBody = $b;
                }
            } elseif (is_object($exception) && method_exists($exception, 'getResponseBody')) {
                $b = $exception->getResponseBody();
                if (is_string($b) && trim($b) !== '') {
                    $rawBody = $b;
                }
            }
            if ($rawBody === '' && is_string($exception->getMessage()) && trim($exception->getMessage()) !== '') {
                $rawBody = $exception->getMessage();
            }

            return [
                'ok' => false,
                'error' => $exception->getMessage(),
                'status' => 'API probe failed.',
                'raw_response' => $rawBody,
                'exception_class' => $exception::class,
                'used_auth_id_prefix_suffix' => $this->client->authUserIdPrefixSuffix(),
                'used_api_key_prefix_suffix' => $this->client->apiKeyPrefixSuffix(),
            ];
        }
    }

    /**
     * @return iterable<int, array<string, mixed>>
     */
    public function listDomains(SyncContext $context): iterable
    {
        unset($context);

        $noOfRecords = 500;
        $page = 1;
        do {
            $response = $this->client->get('domains/search.json', [
                'order-by' => ['creationtime desc'],
                'show-child-orders' => false,
                'status' => ['Active', 'Suspended', 'Pending Delete Restorable', 'Deleted', 'Archived'],
                'page-no' => $page,
                'no-of-records' => $noOfRecords,
            ]);
            if (! is_array($response) || $response === []) {
                return;
            }
            $count = 0;
            foreach ($response as $orderId => $row) {
                if (! is_array($row)) {
                    continue;
                }
                if (isset($row['entitytypeid']) && (string) $row['entitytypeid'] !== '2') {
                    continue;
                }
                ++$count;
                $domainName = strtolower(trim((string) ($row['domainname'] ?? $row['description'] ?? '')));
                if ($domainName === '') {
                    continue;
                }
                $creationTime = $this->normalizeDate($row['creationtime'] ?? null);
                $endTime = $this->normalizeDate($row['endtime'] ?? null);
                $customerId = (string) ($row['customerid'] ?? '');
                yield [
                    'provider' => $this->code(),
                    'domain_name' => $domainName,
                    'tld' => $this->extractTld($domainName),
                    'upstream_domain_id' => $row['entityid'] ?? null,
                    'upstream_order_id' => is_string($orderId) ? $orderId : (string) $orderId,
                    'registrar_status' => $this->mapRegistrarStatus($row),
                    'customer_id' => $customerId !== '' ? $customerId : ($this->defaultCustomerId ?? null),
                    'registered_at' => $creationTime,
                    'expires_at' => $endTime,
                    'renewal_due_at' => $endTime,
                    'auto_renew_enabled' => $row['recurring'] ?? null,
                    'owner_type' => 'registrar',
                    'owner_id' => 'pool:' . $this->code(),
                    'tenant_id' => 'registrar:' . $this->code(),
                    'billing_tenant_id' => 'registrar:' . $this->code(),
                    'billing_mode' => 'registrar',
                    'raw' => $row,
                ];
            }
            ++$page;
        } while ($count >= $noOfRecords);
    }

    private function authUserIdOrDefault(): string
    {
        $default = (string) ($this->defaultCustomerId ?? '');
        if ($default !== '') {
            return $default;
        }
        $values = getenv('NETEARTHONE_AUTH_USER_ID') ?: getenv('NEO_AUTH_USER_ID') ?: getenv('RESELLER_ID') ?: getenv('AUTH_USER_ID') ?: '';
        if (is_string($values) && trim($values) !== '') {
            return trim($values);
        }
        return '';
    }

    private function extractTld(string $domainName): string
    {
        try {
            [, $tld] = $this->splitDomain($domainName);
            return (string) $tld;
        } catch (Throwable) {
            $dot = strpos($domainName, '.');
            if ($dot === false || $dot === 0) {
                return $domainName;
            }
            return ltrim(substr($domainName, $dot), '.');
        }
    }

    public function checkAvailability(string $domainName, SyncContext $context): array
    {
        unset($context);

        [$label, $tld] = $this->splitDomain($domainName);
        $response = $this->client->get(
            'domains/available.json',
            [
                'domain-name' => [$label],
                'tlds' => [$tld],
            ],
        );

        $status = $this->extractAvailabilityStatus($response, $domainName, $tld);
        $available = $status === 'available';

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => $domainName,
            'available' => $available,
            'status' => $status,
            'message' => match ($status) {
                'available' => 'Domain is available for registration.',
                'regthroughus' => 'Domain is already registered through this reseller account.',
                'regthroughothers' => 'Domain is already registered elsewhere.',
                default => 'Domain availability is currently unknown.',
            },
            'raw' => $response,
        ];
    }

    public function fetchDomain(string $domainName, SyncContext $context): array
    {
        return $this->syncDomain($domainName, $context);
    }

    public function syncDomain(string $domainName, SyncContext $context): array
    {
        unset($context);

        $response = $this->client->get(
            'domains/details-by-name.json',
            [
                'domain-name' => $domainName,
                'options' => ['All'],
            ],
        );

        if ($this->isErrorResponse($response)) {
            return [
                'ok' => false,
                'provider' => $this->code(),
                'domain_name' => $domainName,
                'message' => (string) ($response['message'] ?? $response['error'] ?? 'NetEarthOne sync failed.'),
                'raw' => $response,
            ];
        }

        $statuses = [];
        foreach ($this->normalizeStatuses($response['orderstatus'] ?? null) as $status) {
            $statuses[] = ['code' => $status, 'label' => 'provider'];
        }
        foreach ($this->normalizeStatuses($response['domainstatus'] ?? null) as $status) {
            $statuses[] = ['code' => $status, 'label' => 'domain'];
        }
        if (isset($response['currentstatus']) && trim((string) $response['currentstatus']) !== '') {
            $statuses[] = ['code' => (string) $response['currentstatus'], 'label' => 'order'];
        }

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => (string) ($response['domainname'] ?? $domainName),
            'upstream_domain_id' => $response['entityid'] ?? null,
            'upstream_order_id' => $response['orderid'] ?? $response['entityid'] ?? null,
            'registrar_status' => $this->mapRegistrarStatus($response),
            'registered_at' => $this->normalizeDate($response['creationtime'] ?? null),
            'expires_at' => $this->normalizeDate($response['endtime'] ?? null),
            'renewal_due_at' => $this->normalizeDate($response['endtime'] ?? null),
            'auto_renew_enabled' => $response['recurring'] ?? null,
            'nameservers' => $this->extractNameservers($response),
            'statuses' => $statuses,
            'raw' => $response,
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
        $domainName = strtolower(trim((string) ($payload['domain_name'] ?? '')));
        $customerId = trim((string) ($payload['customer_id'] ?? $this->defaultCustomerId ?? ''));
        $registrant = trim((string) ($payload['registrant'] ?? ''));
        $admin = trim((string) ($payload['contacts']['admin'] ?? ''));
        $tech = trim((string) ($payload['contacts']['tech'] ?? ''));
        $billing = trim((string) ($payload['contacts']['billing'] ?? ''));
        $nameservers = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) (is_array($item) ? ($item['hostname'] ?? '') : $item)),
            is_array($payload['nameservers'] ?? null) ? $payload['nameservers'] : [],
        )));

        if ($domainName === '' || $customerId === '' || $registrant === '' || $admin === '' || $tech === '' || $billing === '') {
            throw new RuntimeException('NetEarthOne registration requires a domain name, customer ID, and all contact IDs.');
        }

        if (count($nameservers) < 2) {
            throw new RuntimeException('NetEarthOne registration requires at least two nameservers.');
        }

        $response = $this->client->post(
            'domains/register.json',
            [
                'domain-name' => $domainName,
                'years' => max(1, (int) ($payload['period_years'] ?? 1)),
                'ns' => $nameservers,
                'customer-id' => $customerId,
                'reg-contact-id' => $registrant,
                'admin-contact-id' => $admin,
                'tech-contact-id' => $tech,
                'billing-contact-id' => $billing,
                'invoice-option' => (string) ($payload['invoice_option'] ?? $this->defaultInvoiceOption),
                'purchase-privacy' => ($payload['purchase_privacy'] ?? false) === true,
            ],
        );

        if ($this->isErrorResponse($response)) {
            return [
                'ok' => false,
                'provider' => $this->code(),
                'domain_name' => $domainName,
                'message' => (string) ($response['message'] ?? $response['error'] ?? 'NetEarthOne registration failed.'),
                'raw' => $response,
            ];
        }

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => $domainName,
            'message' => (string) ($response['actionstatusdesc'] ?? $response['description'] ?? 'Registration submitted successfully.'),
            'upstream_domain_id' => $response['entityid'] ?? null,
            'upstream_order_id' => $response['entityid'] ?? $response['orderid'] ?? null,
            'registrar_status' => 'active',
            'raw' => $response,
        ];
    }

    public function renewDomain(string $domainName, int $periodYears, array $options = []): array
    {
        $domain = $this->syncDomain($domainName, new SyncContext($this->code(), 'renewal'));
        if (! ($domain['ok'] ?? false)) {
            return $domain;
        }

        $orderId = (string) ($options['order_id'] ?? $domain['upstream_order_id'] ?? '');
        $expiresAt = (string) ($domain['expires_at'] ?? '');
        if ($orderId === '' || $expiresAt === '') {
            throw new RuntimeException('NetEarthOne renewal requires an order ID and current expiry date.');
        }

        $response = $this->client->post(
            'domains/renew.json',
            [
                'order-id' => $orderId,
                'years' => $periodYears,
                'exp-date' => strtotime($expiresAt),
                'invoice-option' => (string) ($options['invoice_option'] ?? $this->defaultInvoiceOption),
                'auto-renew' => ($options['auto_renew'] ?? false) === true,
            ],
        );

        if ($this->isErrorResponse($response)) {
            return [
                'ok' => false,
                'provider' => $this->code(),
                'domain_name' => $domainName,
                'message' => (string) ($response['message'] ?? $response['error'] ?? 'NetEarthOne renewal failed.'),
                'raw' => $response,
            ];
        }

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => $domainName,
            'message' => (string) ($response['actionstatusdesc'] ?? 'Renewal submitted successfully.'),
            'upstream_order_id' => $orderId,
            'raw' => $response,
        ];
    }

    /**
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $nameservers
     * @param array{auth_info?: string|null, require_host_objects?: bool} $options
     * @return array<string, mixed>
     */
    public function updateNameservers(string $domainName, array $nameservers, array $options = []): array
    {
        unset($options);

        $domain = $this->syncDomain($domainName, new SyncContext($this->code(), 'nameserver-update'));
        if (! ($domain['ok'] ?? false)) {
            return $domain;
        }

        $orderId = (string) ($domain['upstream_order_id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('NetEarthOne nameserver updates require an order ID.');
        }

        $hostnames = array_values(array_filter(array_map(
            static fn (mixed $item): string => trim((string) ($item['hostname'] ?? '')),
            $nameservers,
        )));

        if (count($hostnames) < 2) {
            throw new RuntimeException('NetEarthOne nameserver updates require at least two nameservers.');
        }

        $response = $this->client->post(
            'domains/modify-ns.json',
            [
                'order-id' => $orderId,
                'ns' => $hostnames,
            ],
        );

        if ($this->isErrorResponse($response)) {
            return [
                'ok' => false,
                'provider' => $this->code(),
                'domain_name' => $domainName,
                'message' => (string) ($response['message'] ?? $response['error'] ?? 'NetEarthOne nameserver update failed.'),
                'raw' => $response,
            ];
        }

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => $domainName,
            'message' => (string) ($response['actionstatusdesc'] ?? 'Nameserver update submitted successfully.'),
            'upstream_order_id' => $orderId,
            'nameservers' => $hostnames,
            'raw' => $response,
        ];
    }

    /**
     * $registrant is the raw registry registrant handle ID (optional for some TLDs).
     * $contacts maps contact role => registry handle ID; allowed keys are admin, tech, billing.
     *
     * @param array{registrant?: string|null, admin?: string|null, tech?: string|null, billing?: string|null} $contacts
     * @return array<string, mixed>
     */
    public function updateContacts(string $domainName, array $contacts): array
    {
        $domain = $this->syncDomain($domainName, new SyncContext($this->code(), 'contact-update'));
        if (! ($domain['ok'] ?? false)) {
            return $domain;
        }

        $orderId = (string) ($domain['upstream_order_id'] ?? '');
        if ($orderId === '') {
            throw new RuntimeException('NetEarthOne contact updates require an order ID.');
        }

        $registrantContactId = trim((string) ($contacts['registrant'] ?? ''));
        $adminContactId = trim((string) ($contacts['admin'] ?? ''));
        $techContactId = trim((string) ($contacts['tech'] ?? ''));
        $billingContactId = trim((string) ($contacts['billing'] ?? ''));

        if ($registrantContactId === '' && $adminContactId === '' && $techContactId === '' && $billingContactId === '') {
            return [
                'ok' => false,
                'provider' => $this->code(),
                'domain_name' => $domainName,
                'message' => 'Provide at least one contact role (registrant, admin, tech, or billing) to update.',
            ];
        }

        $payload = ['order-id' => $orderId];
        if ($registrantContactId !== '') { $payload['reg-contact-id'] = $registrantContactId; }
        if ($adminContactId !== '') { $payload['admin-contact-id'] = $adminContactId; }
        if ($techContactId !== '') { $payload['tech-contact-id'] = $techContactId; }
        if ($billingContactId !== '') { $payload['billing-contact-id'] = $billingContactId; }

        $response = $this->client->post('domains/modify-contact.json', $payload);

        if ($this->isErrorResponse($response)) {
            return [
                'ok' => false,
                'provider' => $this->code(),
                'domain_name' => $domainName,
                'message' => (string) ($response['message'] ?? $response['error'] ?? 'NetEarthOne contact update failed.'),
                'raw' => $response,
            ];
        }

        return [
            'ok' => true,
            'provider' => $this->code(),
            'domain_name' => $domainName,
            'message' => (string) ($response['actionstatusdesc'] ?? 'Contact update submitted successfully.'),
            'upstream_order_id' => $orderId,
            'contacts' => $contacts,
            'raw' => $response,
        ];
    }

    /**
     * @return array{0: string, 1: string}
     */
    private function splitDomain(string $domainName): array
    {
        $parts = explode('.', strtolower(trim($domainName)));
        if (count($parts) < 2) {
            throw new RuntimeException(sprintf('Invalid domain name "%s".', $domainName));
        }

        $label = array_shift($parts);
        if ($label === null || $label === '') {
            throw new RuntimeException(sprintf('Invalid domain name "%s".', $domainName));
        }

        return [$label, implode('.', $parts)];
    }

    /**
     * @param array<string, mixed> $response
     */
    private function extractAvailabilityStatus(array $response, string $domainName, string $tld): string
    {
        $needle = strtolower($domainName);

        foreach ($response as $key => $value) {
            if (! is_array($value)) {
                continue;
            }

            $normalizedKey = strtolower((string) $key);
            if ($normalizedKey !== $needle && $normalizedKey !== strtolower($domainName . '.' . $tld)) {
                continue;
            }

            $status = strtolower((string) ($value['status'] ?? 'unknown'));

            return $status !== '' ? $status : 'unknown';
        }

        return 'unknown';
    }

    /**
     * @param array<string, mixed> $response
     * @return list<string>
     */
    private function extractNameservers(array $response): array
    {
        $nameservers = [];

        foreach ($response as $key => $value) {
            if (! is_string($key) || ! str_starts_with($key, 'ns')) {
                continue;
            }

            $hostname = trim((string) $value);
            if ($hostname !== '') {
                $nameservers[] = $hostname;
            }
        }

        return $nameservers;
    }

    /**
     * @return list<string>
     */
    private function normalizeStatuses(mixed $value): array
    {
        if (is_array($value)) {
            return array_values(array_filter(array_map(
                static fn (mixed $item): string => trim((string) $item),
                $value,
            )));
        }

        $status = trim((string) $value);
        if ($status === '') {
            return [];
        }

        return array_values(array_filter(array_map('trim', explode(',', $status))));
    }

    /**
     * @param array<string, mixed> $response
     */
    private function mapRegistrarStatus(array $response): string
    {
        $status = strtolower(trim((string) ($response['currentstatus'] ?? '')));
        if ($status === '') {
            $status = strtolower(trim((string) ($response['orderstatus'] ?? '')));
        }

        return match (true) {
            str_contains($status, 'active') => 'active',
            str_contains($status, 'pending') => 'pending',
            str_contains($status, 'suspend') => 'suspended',
            str_contains($status, 'delete') => 'pending_delete',
            str_contains($status, 'archive') => 'archived',
            default => $status !== '' ? $status : 'active',
        };
    }

    private function normalizeDate(mixed $value): ?string
    {
        if ($value === null || $value === '') {
            return null;
        }

        if (is_numeric($value)) {
            return date('Y-m-d H:i:s', (int) $value);
        }

        $timestamp = strtotime((string) $value);

        return $timestamp === false ? (string) $value : date('Y-m-d H:i:s', $timestamp);
    }

    /**
     * @param array<string, mixed> $response
     */
    private function isErrorResponse(array $response): bool
    {
        $status = strtolower((string) ($response['status'] ?? ''));
        if ($status === 'error') {
            return true;
        }

        return isset($response['error']) && trim((string) $response['error']) !== '';
    }
}
