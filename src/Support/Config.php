<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

use Hypervel\Container\Container;
use Hypervel\Contracts\Config\Repository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Ipsocode\Acl\Exceptions\TenantNotEnabled;
use Ipsocode\Acl\Exceptions\WorkspaceNotEnabled;
use ValueError;

class Config
{
    public const DEFAULT_USER_PIVOT_KEY = 'user_id';

    public const DEFAULT_WORKSPACE_TABLE = 'acl_workspaces';

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

    /**
     * Get the org role table's user column: acl.column_names.user_pivot_key, else user_id.
     */
    public static function userPivotKey(): string
    {
        return self::repository()->get('acl.column_names.user_pivot_key') ?? self::DEFAULT_USER_PIVOT_KEY;
    }

    /**
     * Get the org role table's team column, which is the permission config's team foreign key.
     *
     * It reads the repository, not the registrar, which may be resolving this class while it is built.
     */
    public static function teamPivotKey(): string
    {
        return self::repository()->string('permission.column_names.team_foreign_key', PermissionRegistrar::DEFAULT_TEAM_FOREIGN_KEY);
    }

    /**
     * Get the role model class of the permission config.
     *
     * It reads the repository, not the registrar, which may be resolving this class while it is built.
     *
     * @return class-string
     */
    public static function roleModel(): string
    {
        /** @var class-string $model */
        $model = self::repository()->string('permission.models.role');

        return $model;
    }

    /**
     * Get the permissions table name of the permission config.
     */
    public static function permissionsTable(): string
    {
        return self::repository()->string('permission.table_names.permissions');
    }

    /**
     * Get the table of the package's workspace model: acl.table_names.workspace, else acl_workspaces.
     */
    public static function workspaceTable(): string
    {
        return self::repository()->get('acl.table_names.workspace') ?? self::DEFAULT_WORKSPACE_TABLE;
    }

    /**
     * Get the configured key type of the package's tables, or null to copy the permission tables.
     */
    public static function keyType(): ?string
    {
        return self::repository()->get('acl.key_type');
    }

    /**
     * Get the URI path the management UI is served from, with no surrounding slashes.
     */
    public static function uiPath(): string
    {
        return trim(self::repository()->string('acl.ui.path'), '/');
    }

    /**
     * Determine if the management UI is enabled with a frontend.
     */
    public static function uiEnabled(): bool
    {
        return self::repository()->boolean('acl.ui.enabled') && self::uiFrontend() !== null;
    }

    /**
     * Get the frontend of the management UI, or null for the API only.
     *
     * @throws ValueError
     */
    public static function uiFrontend(): ?Frontend
    {
        $frontend = self::repository()->get('acl.ui.frontend');

        return $frontend === null ? null : Frontend::from($frontend);
    }
}
