<?php

declare(strict_types=1);

namespace App\Domain\Provider\Contracts;

use App\Domain\Sync\SyncContext;

interface AvailabilityCheckInterface
{
    /**
     * @return array<string, mixed>
     */
    public function checkAvailability(string $domainName, SyncContext $context): array;
}
