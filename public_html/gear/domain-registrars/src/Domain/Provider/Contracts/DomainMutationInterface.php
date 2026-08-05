<?php

declare(strict_types=1);

namespace App\Domain\Provider\Contracts;

interface DomainMutationInterface
{
    /**
     * @param array<string, mixed> $payload
     * @return array<string, mixed>
     */
    public function registerDomain(array $payload): array;

    /**
     * @param array<string, mixed> $options
     * @return array<string, mixed>
     */
    public function renewDomain(string $domainName, int $periodYears, array $options = []): array;

    /**
     * @param list<array{hostname: string, ipv4?: string|null, ipv6?: string|null}> $nameservers
     * @param array{auth_info?: string|null, require_host_objects?: bool} $options
     * @return array<string, mixed>
     */
    public function updateNameservers(string $domainName, array $nameservers, array $options = []): array;

    /**
     * $registrant is the raw registry registrant handle ID (optional for some TLDs).
     * $contacts maps contact role => registry handle ID; allowed keys are admin, tech, billing.
     *
     * @param array{registrant?: string|null, admin?: string|null, tech?: string|null, billing?: string|null} $contacts
     * @return array<string, mixed>
     */
    public function updateContacts(string $domainName, array $contacts): array;
}
