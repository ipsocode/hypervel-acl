# Installation

Require the package and publish the config:

```sh
composer require ipsocode/hypervel-acl
php artisan vendor:publish --tag=acl-config
```

The package builds on the framework's permission tables instead of replacing them, and on its teams feature. In `config/permission.php`, set `teams` to `true`, set `events_enabled` to `true` (the package rebuilds member access from the permission events) and, if you have your own team model, name it in `models.team`. Then run the setup command:

```sh
php artisan acl:setup
```

It publishes the framework's permission migration if your application has none.

When `acl.models.team` is unset (it takes `permission.models.team` by default), the package uses its own `Ipsocode\Acl\Models\AclTeam` and loads the migration that creates its `acl_teams` table itself; publish it with the `acl-migrations` tag to customize it. The package always loads the migration for the `acl_team_user` membership table, which references the team table, so the team table's migration must run first. For a team model of your own, the setup command adds a migration that gives its table the inheritance column (`acl.column_names.teams_inherit_key`). With `acl.tenant` set to `true`, it also adds one that puts the tenant column (`acl.column_names.tenant_foreign_key`) on the five permission tables and the team table, wherever it is missing.

If the permission tables already exist without team columns, run the framework's `permission:setup-teams` first. Run `php artisan migrate` afterwards.
