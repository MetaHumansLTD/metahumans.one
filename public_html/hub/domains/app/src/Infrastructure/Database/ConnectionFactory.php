<?php

declare(strict_types=1);

namespace App\Infrastructure\Database;

use App\Config\AppConfig;
use PDO;
use RuntimeException;

final class ConnectionFactory
{
    public static function shared(AppConfig $config): PDO
    {
        if (function_exists('database_getConnectionById')) {
            /** @var callable(string): PDO $resolver */
            $resolver = 'database_getConnectionById';

            return self::configurePdo(
                $resolver($config->string('SHARED_DB_CONFIG_ID', 'db_domain_registrars_shared')),
            );
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
}
