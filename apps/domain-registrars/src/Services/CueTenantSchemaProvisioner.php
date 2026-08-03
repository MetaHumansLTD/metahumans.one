<?php

declare(strict_types=1);

namespace App\Services;

use App\Application;
use App\Infrastructure\Database\ConnectionFactory;
use App\Infrastructure\Database\Database;
use App\Infrastructure\Database\SchemaLoader;
use PDO;

final class CueTenantSchemaProvisioner
{
    public function __construct(
        private readonly Application $app,
    ) {
    }

    /**
     * @return array{
     *   shared_schema:string,
     *   tenant_source:string,
     *   tenant_count:int,
     *   tenants:list<array{tenant_id:string,db_config_id:string,status:string,error:?string}>
     * }
     */
    public function provisionAll(): array
    {
        $this->app->sharedSchemaLoader()->load();

        $tenantConfigs = $this->discoverTenantConfigurations();
        $tenantLoaderPath = $this->app->rootPath() . '/database/tenant-schema.sql';
        $results = [];

        foreach ($tenantConfigs['tenants'] as $tenant) {
            $error = null;

            try {
                $database = new Database(
                    ConnectionFactory::tenantByConfigId($this->app->config(), $tenant['db_config_id']),
                );
                (new SchemaLoader($database, $tenantLoaderPath))->load();
                $status = 'loaded';
            } catch (\Throwable $throwable) {
                $status = 'failed';
                $error = $throwable->getMessage();
            }

            $results[] = [
                'tenant_id' => $tenant['tenant_id'],
                'db_config_id' => $tenant['db_config_id'],
                'status' => $status,
                'error' => $error,
            ];
        }

        return [
            'shared_schema' => 'loaded',
            'tenant_source' => $tenantConfigs['source'],
            'tenant_count' => count($results),
            'tenants' => $results,
        ];
    }

    /**
     * @return array{
     *   source:string,
     *   tenants:list<array{tenant_id:string,db_config_id:string}>
     * }
     */
    private function discoverTenantConfigurations(): array
    {
        $fromEnv = $this->fromEnvConfigIds();
        if ($fromEnv !== []) {
            return [
                'source' => 'env:CUE_TENANT_DB_CONFIG_IDS',
                'tenants' => $fromEnv,
            ];
        }

        $fromControlPlane = $this->fromControlPlane();
        if ($fromControlPlane !== []) {
            return [
                'source' => 'cue:control_plane',
                'tenants' => $fromControlPlane,
            ];
        }

        $fromTenantMap = $this->fromTenantMap();
        if ($fromTenantMap !== []) {
            return [
                'source' => 'cue:tenant_contexts',
                'tenants' => $fromTenantMap,
            ];
        }

        return [
            'source' => 'none',
            'tenants' => [],
        ];
    }

    /**
     * @return list<array{tenant_id:string,db_config_id:string}>
     */
    private function fromEnvConfigIds(): array
    {
        $configIds = $this->app->config()->csv('CUE_TENANT_DB_CONFIG_IDS');
        $tenants = [];

        foreach ($configIds as $configId) {
            $tenants[] = [
                'tenant_id' => $configId,
                'db_config_id' => $configId,
            ];
        }

        return $tenants;
    }

    /**
     * @return list<array{tenant_id:string,db_config_id:string}>
     */
    private function fromControlPlane(): array
    {
        if (!function_exists('mh_control_plane_get_pdo')) {
            return [];
        }

        try {
            $pdo = mh_control_plane_get_pdo();
            if (!$pdo instanceof PDO) {
                return [];
            }

            $statement = $pdo->query(
                "SELECT tenant_id, db_config_id
                 FROM mh_control.tenant_db_map
                 WHERE db_config_id IS NOT NULL AND db_config_id <> ''
                 ORDER BY tenant_id ASC",
            );

            $rows = $statement !== false ? $statement->fetchAll(PDO::FETCH_ASSOC) : [];
            return $this->normalizeTenantRows(is_array($rows) ? $rows : []);
        } catch (\Throwable) {
            return [];
        }
    }

    /**
     * @return list<array{tenant_id:string,db_config_id:string}>
     */
    private function fromTenantMap(): array
    {
        $path = null;

        if (function_exists('mh_load_tenant_map_path')) {
            $path = mh_load_tenant_map_path();
        }

        if ((!is_string($path) || $path === '') && $this->app->config()->nullableString('CUE_TENANT_CONTEXTS_PATH') !== null) {
            $path = $this->app->config()->string('CUE_TENANT_CONTEXTS_PATH');
        }

        if ((!is_string($path) || $path === '') && $this->app->config()->nullableString('CUE_CONFIG_DIR') !== null) {
            $path = rtrim($this->app->config()->string('CUE_CONFIG_DIR'), '/\\') . DIRECTORY_SEPARATOR . 'tenant-contexts.json';
        }

        if (!is_string($path) || $path === '' || !is_file($path)) {
            return [];
        }

        $decoded = json_decode((string) file_get_contents($path), true);
        if (!is_array($decoded)) {
            return [];
        }

        $rows = [];
        foreach ($decoded as $tenantId => $entry) {
            if (!is_array($entry)) {
                continue;
            }

            $rows[] = [
                'tenant_id' => (string) $tenantId,
                'db_config_id' => (string) ($entry['db_config_id'] ?? ''),
            ];
        }

        return $this->normalizeTenantRows($rows);
    }

    /**
     * @param list<array<string, mixed>> $rows
     * @return list<array{tenant_id:string,db_config_id:string}>
     */
    private function normalizeTenantRows(array $rows): array
    {
        $tenants = [];
        $seen = [];

        foreach ($rows as $row) {
            $tenantId = trim((string) ($row['tenant_id'] ?? ''));
            $configId = trim((string) ($row['db_config_id'] ?? ''));
            if ($tenantId === '' || $configId === '') {
                continue;
            }

            $key = $tenantId . '|' . $configId;
            if (isset($seen[$key])) {
                continue;
            }
            $seen[$key] = true;

            $tenants[] = [
                'tenant_id' => $tenantId,
                'db_config_id' => $configId,
            ];
        }

        return $tenants;
    }
}
