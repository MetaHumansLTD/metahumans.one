<?php

declare(strict_types=1);

namespace App\Domain\Provider\Contracts;

use App\Domain\Sync\SyncContext;

interface DomainPortfolioSyncInterface
{
    /**
     * @return iterable<array<string, mixed>>
     */
    public function listDomains(SyncContext $context): iterable;

    /**
     * @return array<string, mixed>
     */
    public function fetchDomain(string $domainName, SyncContext $context): array;

    /**
     * @return array<string, mixed>
     */
    public function syncDomain(string $domainName, SyncContext $context): array;
}
