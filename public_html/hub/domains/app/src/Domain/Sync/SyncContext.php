<?php

declare(strict_types=1);

namespace App\Domain\Sync;

final class SyncContext
{
    /**
     * @param array<string, mixed> $filters
     */
    public function __construct(
        public readonly string $providerCode,
        public readonly string $triggeredBy,
        public readonly bool $fullSync = false,
        public readonly bool $dryRun = false,
        public readonly array $filters = [],
    ) {
    }
}
