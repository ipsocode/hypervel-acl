<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Exceptions\TenantNotEnabled;
use Ipsocode\Acl\Exceptions\WorkspaceNotEnabled;

class Config
{
    /**
     * Get the config repository.
     */
    protected static function repository(): Repository
    {
        return Container::getInstance()->make('config');
    }

    /**
     * Determine if tenant are enabled.
     */
    public static function tenantEnabled(): bool
    {
        return self::repository()->boolean('acl.tenant');
    }

    /**
     * Ensure tenant are enabled.
     */
    public static function ensureTenantEnabled(): void
    {
        if (! self::tenantEnabled()) {
            throw TenantNotEnabled::create();
        }
    }

    /**
     * Determine if workspace are enabled.
     */
    public static function workspaceEnabled(): bool
    {
        return self::repository()->boolean('acl.workspace');
    }

    /**
     * Ensure workspace are enabled.
     */
    public static function ensureWorkspaceEnabled(): void
    {
        if (! self::workspaceEnabled()) {
            throw WorkspaceNotEnabled::create();
        }
    }

    /**
     * Get the guards the roles of teams may belong to.
     *
     * @return list<string>
     */
    public static function guards(): array
    {
        /** @var list<string> $guards */
        $guards = self::repository()->array('acl.guards');

        return $guards;
    }

    /**
     * Get the team model class, or null to use the package's.
     *
     * @return null|class-string
     */
    public static function teamModel(): ?string
    {
        return self::repository()->get('acl.models.team');
    }

    /**
     * Get the workspace model class, or null when workspaces are flat partitions.
     *
     * @return null|class-string<Model>
     */
    public static function workspaceModel(): ?string
    {
        return self::repository()->get('acl.models.workspace');
    }

    /**
     * Get the column of the team table that holds the inherited team.
     */
    public static function teamsInheritKey(): string
    {
        return self::repository()->string('acl.column_names.teams_inherit_key');
    }

    /**
     * Get the column of the team table that holds the tenant, or null for a single tenant.
     */
    public static function tenantForeignKey(): ?string
    {
        return self::repository()->get('acl.column_names.tenant_foreign_key');
    }

    /**
     * Get the column of the workspace model that holds the workspace it is beneath.
     */
    public static function workspaceParentColumn(): string
    {
        return self::repository()->string('acl.column_names.workspace_parent');
    }

    /**
     * Get the column that partitions the permission tables, or null when workspaces are off.
     */
    public static function workspaceColumn(): ?string
    {
        return self::repository()->get('acl.column_names.workspace_foreign_key');
    }
}
