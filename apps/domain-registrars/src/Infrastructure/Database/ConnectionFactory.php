<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Config\AppConfig;
use PDO;
use RuntimeException;
use Throwable;

final class ConnectionFactory
{
    public static function shared(AppConfig $config): PDO
    {
        $sharedConfig = self::resolveSharedCueConfiguration($config);
        if ($sharedConfig !== null) {
            $configId = (string) ($sharedConfig['id'] ?? '');
            if ($configId !== '' && function_exists('database_getConnectionById')) {
                /** @var callable(string): PDO $resolver */
                $resolver = 'database_getConnectionById';

                return self::configurePdo($resolver($configId));
            }

            if (function_exists('database_getConnectionFromConfig')) {
                /** @var callable(array): PDO $resolver */
                $resolver = 'database_getConnectionFromConfig';

                return self::configurePdo($resolver($sharedConfig));
            }
        }

        if (function_exists('database_getConnectionById')) {
            /** @var callable(string): PDO $resolver */
            $resolver = 'database_getConnectionById';

            foreach (self::sharedLookupCandidates($config) as $candidate) {
                try {
                    return self::configurePdo($resolver($candidate));
                } catch (Throwable) {
                    continue;
                }
            }
        }

        return self::makeFromConfig(
            $config,
            dsnKey: 'SHARED_DB_DSN',
            userKey: 'SHARED_DB_USER',
            passwordKey: 'SHARED_DB_PASSWORD',
        );
    }

    public static function tenant(AppConfig $config): PDO
    {
        return self::tenantByConfigId($config, $config->nullableString('TENANT_DB_CONFIG_ID'));
    }

    public static function tenantByConfigId(AppConfig $config, ?string $configId): PDO
    {
        if (function_exists('database_getContextAwareConnection')) {
            /** @var callable(?string): PDO $resolver */
            $resolver = 'database_getContextAwareConnection';

            return self::configurePdo($resolver($configId));
        }

        return self::makeFromConfig(
            $config,
            dsnKey: 'TENANT_DB_DSN',
            userKey: 'TENANT_DB_USER',
            passwordKey: 'TENANT_DB_PASSWORD',
        );
    }

    public static function make(AppConfig $config): PDO
    {
        return self::tenant($config);
    }

    private static function makeFromConfig(
        AppConfig $config,
        string $dsnKey,
        string $userKey,
        string $passwordKey,
    ): PDO {
        $dsn = $config->nullableString($dsnKey) ?? $config->nullableString('DB_DSN');
        if ($dsn === null) {
            throw new RuntimeException(sprintf('Missing required database DSN (%s or DB_DSN).', $dsnKey));
        }

        $user = $config->nullableString($userKey) ?? $config->nullableString('DB_USER');
        $password = $config->nullableString($passwordKey) ?? $config->nullableString('DB_PASSWORD');
        $directory = null;
        if (str_starts_with($dsn, 'sqlite:')) {
            $path = substr($dsn, 7);
            $directory = dirname($path);
        }

        if ($directory !== null && $directory !== '.' && ! is_dir($directory)) {
            mkdir($directory, 0777, true);
        }

        return self::configurePdo(new PDO(
            $dsn,
            $user,
            $password,
            [
                PDO::ATTR_ERRMODE => PDO::ERRMODE_EXCEPTION,
                PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
            ],
        ));
    }

    private static function configurePdo(PDO $pdo): PDO
    {
        $pdo->setAttribute(PDO::ATTR_ERRMODE, PDO::ERRMODE_EXCEPTION);
        $pdo->setAttribute(PDO::ATTR_DEFAULT_FETCH_MODE, PDO::FETCH_ASSOC);

        $driver = (string) $pdo->getAttribute(PDO::ATTR_DRIVER_NAME);
        if ($driver === 'sqlite') {
            $pdo->exec('PRAGMA foreign_keys = ON');
        }

        return $pdo;
    }

    /**
     * Resolve the shared registrar database from live CUE configuration rather than
     * assuming the public-facing name is the actual configuration identifier.
     *
     * @return array<string, mixed>|null
     */
    private static function resolveSharedCueConfiguration(AppConfig $config): ?array
    {
        if (! function_exists('database_loadConfigurations')) {
            return null;
        }

        $configurations = database_loadConfigurations();
        if (! is_array($configurations) || $configurations === []) {
            return null;
        }

        foreach (self::sharedConfigReferenceCandidates($config) as $candidate) {
            $match = self::findCueConfigurationByField($configurations, ['id', 'name'], $candidate);
            if ($match !== null) {
                return $match;
            }
        }

        foreach (self::sharedDatabaseNameCandidates($config) as $candidate) {
            $match = self::findCueConfigurationByField($configurations, ['database', 'name'], $candidate);
            if ($match !== null) {
                return $match;
            }
        }

        return null;
    }

    /**
     * @return list<string>
     */
    private static function sharedLookupCandidates(AppConfig $config): array
    {
        return self::uniqueStrings(array_merge(
            self::sharedConfigReferenceCandidates($config),
            self::sharedDatabaseNameCandidates($config),
        ));
    }

    /**
     * @return list<string>
     */
    private static function sharedConfigReferenceCandidates(AppConfig $config): array
    {
        return self::uniqueStrings([
            $config->nullableString('SHARED_DB_CONFIG_ID'),
            $config->nullableString('SHARED_DB_CONFIG_NAME'),
            'db_domain_registrars_shared',
        ]);
    }

    /**
     * @return list<string>
     */
    private static function sharedDatabaseNameCandidates(AppConfig $config): array
    {
        return self::uniqueStrings([
            $config->nullableString('SHARED_DB_DATABASE_NAME'),
            $config->nullableString('SHARED_DB_NAME'),
            'domainname_controller',
        ]);
    }

    /**
     * @param array<string, mixed> $configurations
     * @param list<string> $fields
     * @return array<string, mixed>|null
     */
    private static function findCueConfigurationByField(array $configurations, array $fields, string $candidate): ?array
    {
        $needle = trim($candidate);
        if ($needle === '') {
            return null;
        }

        foreach ($configurations as $configId => $row) {
            if (! is_array($row)) {
                continue;
            }

            $normalized = $row;
            if (! isset($normalized['id']) && is_string($configId) && $configId !== '') {
                $normalized['id'] = $configId;
            }

            foreach ($fields as $field) {
                $value = trim((string) ($normalized[$field] ?? ''));
                if ($value !== '' && strcasecmp($value, $needle) === 0) {
                    return $normalized;
                }
            }
        }

        return null;
    }

    /**
     * @param list<?string> $values
     * @return list<string>
     */
    private static function uniqueStrings(array $values): array
    {
        $normalized = [];

        foreach ($values as $value) {
            if (! is_string($value)) {
                continue;
            }

            $value = trim($value);
            if ($value === '') {
                continue;
            }

            $key = strtolower($value);
            $normalized[$key] = $value;
        }

        return array_values($normalized);
    }
}
