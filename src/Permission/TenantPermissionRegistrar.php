<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Permission;

use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\PermissionPartition;
use Ipsocode\Acl\Tenant;

/**
 * Keeps each tenant's permission cache apart.
 *
 * The framework keys its role and permission catalog by partition only, and the tenant scopes
 * on the role and permission models decide which rows that catalog holds. Without the tenant
 * in the key, the first tenant to load the catalog would serve it to every other tenant.
 */
class TenantPermissionRegistrar extends PermissionRegistrar
{
    public bool $tenant;

    public bool $workspace;

    /**
     * Initialize cache and config-backed registrar state, and whether tenants and workspaces are enabled.
     *
     * Boot or tests only. The values are stored on the singleton registrar and
     * affect every later permission lookup in this worker.
     */
    public function initializeCache(): void
    {
        parent::initializeCache();

        $this->tenant = $this->config->boolean('acl.tenant');
        $this->workspace = $this->config->boolean('acl.workspace');
    }

    /**
     * Add the permission partition, and the current tenant, to a cache key.
     *
     * Every cached catalog, its flushes and the assignment-version token are keyed through here.
     */
    protected function partitionedCacheKey(string $key, ?PermissionPartition $partition = null): string
    {
        $key = parent::partitionedCacheKey($key, $partition);
        $tenant = Tenant::id();

        return $tenant === null ? $key : $key . ':tenant:' . PermissionPartition::encodeCacheSegment($tenant);
    }
}
