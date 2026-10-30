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
    ],

    // Partition the permission tables by workspace, with a Globals team per tenant.
    'workspace' => false,
    'tenant' => false,

    /*
    |--------------------------------------------------------------------------
    | Key Type
    |--------------------------------------------------------------------------
    |
    | The key type of the package's tables: "id", "uuid" or "ulid". Leave it null
    | to copy the permission tables: the type of their id column when they exist,
    | else the one the configured role model uses, else the user model's. The user
    | model's key must be the type of the morph key columns of the permission
    | tables. With the tables not migrated yet, "acl:setup" asks which type to use
    | and writes it to ACL_KEY_TYPE in your .env file.
    |
    */

    'key_type' => env('ACL_KEY_TYPE') ?: null,

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
    | The management UI loads only when "enabled". The assets of the "bundled"
    | frontend are published with the acl-assets tag, the pages of "react" with
    | acl-react. Keep "path" apart from the API's "routes.prefix".
    |
    */

    'ui' => [
        'enabled' => (bool) env('ACL_UI_ENABLED', false),

        // "bundled": the package's React app, prebuilt. "react": the same pages as
        // Inertia pages in your application. null, or an empty ACL_UI_FRONTEND: the API only.
        'frontend' => env('ACL_UI_FRONTEND', 'bundled') ?: null,

        // The subdomain the UI answers on; null serves it under the application's domain.
        'domain' => env('ACL_UI_DOMAIN'),

        // The URI path the UI is served from. Its internal API lives beneath it.
        'path' => env('ACL_UI_PATH', 'acl-admin'),

        // Assigned to every UI route, its internal API included.
        'middleware' => ['web', 'auth'],

        // The permission every UI route requires; remove it only if you replace the check.
        'permission' => 'acl.ui',
    ],
];
