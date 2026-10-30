<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Support;

use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Models\AclTeam;
use RuntimeException;

class Teams
{
    public const MEMBERS_TABLE = 'acl_team_user';

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
        $model = config('acl.models.team') ?? AclTeam::class;

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
     * Get the membership table's team column: acl.column_names.team_pivot_key, else team_id.
     */
    public static function teamPivotKey(): string
    {
        return config('acl.column_names.team_pivot_key') ?? self::DEFAULT_TEAM_PIVOT_KEY;
    }

    /**
     * Get the membership table's user column: acl.column_names.user_pivot_key, else user_id.
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
        if (! config()->boolean('acl.tenant')) {
            return null;
        }

        $column = config('acl.column_names.tenant_foreign_key');

        throw_if($column === null || $column === '', 'Error: tenant_foreign_key on config/acl.php not loaded. Run [php artisan config:clear] and try again.');

        return $column;
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
