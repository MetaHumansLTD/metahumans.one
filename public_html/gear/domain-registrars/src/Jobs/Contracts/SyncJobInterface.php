<?php

declare(strict_types=1);

namespace App\Jobs\Contracts;

interface SyncJobInterface
{
    public function code(): string;

    public function queue(): string;

    /**
     * @return array<string, mixed>
     */
    public function handle(): array;
}
