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

    /*
    | "workspace" is the model of the workspace tree, used when "workspace" below is
    | true: either the package's Ipsocode\Acl\Models\AclWorkspace or your own with a
    | parent column. Leave it null for flat workspaces the application identifies itself.
    */

    'models' => [
        'team' => config('permission.models.team'),
        'workspace' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Table Names
    |--------------------------------------------------------------------------
    |
    | "workspace" is the table of the package's workspace model; leave it null for
    | "acl_workspaces".
    |
    */

    'table_names' => [
        'workspace' => null,
    ],

    /*
    |--------------------------------------------------------------------------
    | Column Names
    |--------------------------------------------------------------------------
    |
    | "workspace_foreign_key" is the column that partitions hypervel/permission's tables when
    | "workspace" below is true; workspace 0 is the home workspace. "workspace_parent"
    | is the column of the workspace model that holds the workspace it is beneath.
    | "tenant_foreign_key" is the column of the team table that holds the tenant a team
    | belongs to; leave it null for a single tenant. A user is in one team per tenant.
    |
    */

    'column_names' => [
        'workspace_foreign_key' => 'workspace_id',
        'workspace_parent' => 'parent_id',
        'teams_inherit_key' => 'inherit_id',
        'tenant_foreign_key' => null,
        'team_foreign_key' => config('permission.column_names.team_foreign_key', 'acl_team_id'),
    ],

    // Partition the permission tables by workspace, with a Globals team per tenant.
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

    /*
    |--------------------------------------------------------------------------
    | UI
    |--------------------------------------------------------------------------
    |
    | The management UI loads only when enabled. "frontend" is "bundled" for the
    | package's own React app, prebuilt and served from the published acl-assets;
    | "react" for the same pages as Inertia pages, published into your application
    | with the acl-react tag; or null for the API only. Keep "path" apart from
    | the API's "routes.prefix".
    |
    */

    /*
    |--------------------------------------------------------------------------
    | UI Domain
    |--------------------------------------------------------------------------
    |
    | This is the subdomain where the ACL management UI will be accessible from.
    | If the setting is null, the UI will reside under the same domain as the
    | application. Otherwise, this value will be used as the subdomain.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | UI Path
    |--------------------------------------------------------------------------
    |
    | This is the URI path where the UI will be accessible from. Feel free to
    | change it to anything you like. Note that the URI will not affect the
    | paths of its internal API that aren't exposed to users.
    |
    */

    /*
    |--------------------------------------------------------------------------
    | UI Route Middleware
    |--------------------------------------------------------------------------
    |
    | These middleware are assigned to every UI route, its internal API
    | included. The permission check enforces "ui.permission" and should only
    | be removed if you replace it with your own.
    |
    */

    'ui' => [
        'enabled' => (bool) env('ACL_UI_ENABLED', false),
        'frontend' => env('ACL_UI_FRONTEND', 'bundled'),
        'domain' => env('ACL_UI_DOMAIN'),
        'path' => env('ACL_UI_PATH', 'acl-admin'),
        'middleware' => ['web', 'auth'],
        'permission' => 'acl.ui',
    ],
];
