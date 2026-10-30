<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Tenant;
use RuntimeException;

class Teams
{
    public const ORGANIZATIONS_TABLE = 'acl_organizations';

    /**
     * The name of the team each tenant has for the roles everyone in it holds.
     */
    public const GLOBALS = 'Globals';

    /**
     * The name of the tenant scope, which `BelongsToTenant` registers and the unscoped queries remove.
     */
    public const TENANT_SCOPE = 'acl_tenant';

    public const DEFAULT_TEAM_PIVOT_KEY = 'team_id';

    public const DEFAULT_USER_PIVOT_KEY = 'user_id';

    /**
     * The precision membership timestamps are written at: a team's change and a member's sync
     * are compared to the millisecond.
     */
    public const DATE_FORMAT = 'Y-m-d H:i:s.v';

    /**
     * Get the class of the team model: acl.models.team, else the package's.
     *
     * @return class-string<Model&Team>
     */
    public static function model(): string
    {
        /** @var class-string<Model&Team> $model */
        $model = Config::teamModel() ?? AclTeam::class;

        return $model;
    }

    /**
     * Determine whether the package's team model is in use, so the package creates the team table.
     */
    public static function usesPackageModel(): bool
    {
        return static::model() === AclTeam::class;
    }

    /**
     * Get the org role table's team column: acl.column_names.team_pivot_key, else team_id.
     */
    public static function teamPivotKey(): string
    {
        return config('acl.column_names.team_pivot_key') ?? self::DEFAULT_TEAM_PIVOT_KEY;
    }

    /**
     * Get the org role table's user column: acl.column_names.user_pivot_key, else user_id.
     */
    public static function userPivotKey(): string
    {
        return config('acl.column_names.user_pivot_key') ?? self::DEFAULT_USER_PIVOT_KEY;
    }

    /**
     * Get the tenant column of the team and permission tables, or null when acl.tenant is off.
     *
     * @throws RuntimeException when acl.tenant is on and acl.column_names.tenant_foreign_key names no column
     */
    public static function tenantColumn(): ?string
    {
        if (! Config::tenantEnabled()) {
            return null;
        }

        $column = Config::tenantForeignKey();

        throw_if($column === null || $column === '', 'Error: tenant_foreign_key on config/acl.php not loaded. Run [php artisan config:clear] and try again.');

        return $column;
    }

    /**
     * Get the tenant's Globals team, creating it: with workspaces on, a team without an inherited
     * team inherits it, so its roles are held by everyone in the tenant.
     *
     * @return Model&Team
     */
    public static function globals(int|string|null $tenant = null): Model
    {
        $column = static::tenantColumn();
        $tenant ??= Tenant::id();

        $query = static::query()->withoutGlobalScope(self::TENANT_SCOPE);
        $attributes = ['name' => self::GLOBALS, ...($column === null ? [] : [$column => $tenant])];

        // Concurrent first calls can both miss, so a lost insert reads back the row that won.
        return (clone $query)->where($attributes)->first() ?? $query->createOrFirst($attributes);
    }

    /**
     * Start a query on the team model.
     *
     * @return Builder<Model&Team>
     */
    public static function query(): Builder
    {
        return static::model()::query();
    }
}
