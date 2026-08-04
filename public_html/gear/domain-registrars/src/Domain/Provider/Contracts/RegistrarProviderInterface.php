<?php

declare(strict_types=1);

namespace App\Domain\Provider\Contracts;

interface RegistrarProviderInterface
{
    public function code(): string;

    public function displayName(): string;

    /**
     * @return list<string>
     */
    public function capabilities(): array;

    public function supports(string $capability): bool;

    /**
     * Returns basic provider health data suitable for `control`.
     *
     * @return array{
     *     ok: bool,
     *     message: string,
     *     metadata?: array<string, mixed>
     * }
     */
    public function healthCheck(): array;
}
