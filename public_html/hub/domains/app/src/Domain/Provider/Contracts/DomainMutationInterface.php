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
     * @return array<string, mixed>
     */
    public function updateNameservers(string $domainName, array $nameservers): array;
}
