<?php

declare(strict_types=1);

return [
    /*
    |--------------------------------------------------------------------------
    | Teams
    |--------------------------------------------------------------------------
    |
    | Teams are the framework's own: set permission.teams to true and name the
    | model in permission.models.team, either the package's Ipsocode\Acl\Models\AclTeam
    | (whose table is "table_names.acl_teams") or your own that uses
    | Ipsocode\Acl\Concerns\IsTeam. A team holds the roles of the team named by its
    | "teams_inherit_id" column, which holds the key of another row of the same table.
    */

    'models' => [
        'team' => config('permission.models.team'),
        // The model used when raw ids are passed to reverse-assignment helpers.
        'workspace' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    */

    'table_names' => [
        'workspace' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    |
    | "workspace" is the column that partitions hypervel/permission's tables; every
    | workspace is a partition the application identifies itself. "tenant_foreign_key"
    | is the column of the team table that holds the tenant a team belongs to; leave
    | it null for a single tenant. A user is in one team per tenant.
    |
    */

    'column_names' => [
        'workspace' => null,
        'teams_inherit_key' => 'inherit_id',
        'tenant_foreign_key' => null,
        'team_foreign_key' => config('permission.column_names.team_foreign_key', 'acl_team_id'),
    ],

    'workspace' => false,
    'tenant' => false,

    /*
    |--------------------------------------------------------------------------
    | Key Type
    |--------------------------------------------------------------------------
    |
    | The key type of the team table: "id", "uuid" or "ulid". Leave it null to
    | copy the permission tables: the type of their id column when they exist,
    | else the one the configured role model uses.
    |
    */

    'key_type' => 'id',
    /*
    |--------------------------------------------------------------------------
    | Guards
    |--------------------------------------------------------------------------
    |
    | The guards the roles of teams may belong to.
    |
    */

    'guards' => ['web'],

    /*
    |--------------------------------------------------------------------------
    | Access Sync
    |--------------------------------------------------------------------------
    |
    | When queued, member access is rebuilt by a queued job that runs in the
    | workspace it was dispatched from.
    |
    */

    'sync' => [
        'queue' => (bool) env('ACL_SYNC_QUEUE', false),
    ],

    /*
    |--------------------------------------------------------------------------
    | Routes
    |--------------------------------------------------------------------------
    |
    | The management API loads only when enabled. Each route requires the
    | permission named in "permissions", which defaults to the route name.
    |
    */

    'routes' => [
        'enabled' => (bool) env('ACL_ROUTES_ENABLED', false),
        'prefix' => 'acl',
        'middleware' => ['api'],
        'name' => 'acl.',
        'permissions' => [],
    ],
];
