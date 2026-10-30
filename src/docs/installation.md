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

When `acl.models.team` is unset (it takes `permission.models.team` by default), the package uses its own `Ipsocode\Acl\Models\AclTeam` and loads the migration that creates its `acl_teams` table itself; publish it with the `acl-migrations` tag to customize it. The package always loads the migration for the `acl_organizations` table of org roles, which references the team table, so the team table's migration must run first. For a team model of your own, the setup command adds a migration that gives its table the inheritance column (`acl.column_names.teams_inherit_key`). With `acl.tenant` set to `true`, it also adds one that puts the tenant column (`acl.column_names.tenant_foreign_key`) on the five permission tables, the team table and the org role table, wherever it is missing.

If the permission tables already exist without team columns, run the framework's `permission:setup-teams` first. Run `php artisan migrate` afterwards.

## UUID and ULID Keys

The package's tables take their key type from the permission tables. With `acl.key_type` left at `null`, the migrations copy the type of the permissions table's `id` column when the table exists, and otherwise the type the configured role model generates (`HasUuids` or `HasUlids`). The team, organization and workspace key columns, and the stubs that add columns to your own team or workspace table, all resolve their type this way, so they match the role keys.

Set `acl.key_type` to `id`, `uuid` or `ulid` before migrating only when the permission tables do not already decide it. A shipped `null` is a change for installs that have not migrated yet; installs that have are unaffected.

Every model that receives assignments must use the same key type as the morph columns of `model_has_roles` and `model_has_permissions`, which `permission.column_names.model_morph_key` renames. Mixing key types makes PostgreSQL reject the comparisons and MySQL and MariaDB lose indexed lookups. See [UUID and ULID Keys](https://github.com/hypervel/components/blob/0.4/src/docs/permission.md#uuid-and-ulid-keys) in the Hypervel documentation.
