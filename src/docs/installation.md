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

### Command Options

Every `acl:*` command first checks that the framework's permission package is active, and stops with a message naming the missing step when it is not:

- The permission service provider is not loaded: do not exclude `hypervel/permission` from package discovery, or register `Hypervel\Permission\PermissionServiceProvider`.
- The permission config has no table names: run `php artisan vendor:publish --tag=permission-config`.
- The permission package has teams off: run `php artisan permission:setup-teams` and set `permission.teams` to `true`.
- The permission tables are not migrated: run `php artisan acl:setup`, then `php artisan migrate`. `acl:setup` itself skips this check, since it publishes the migration.

`acl:setup` takes two options. `--pretend` lists the migrations it would write and writes nothing, not even the bundled UI's assets. `--force` overwrites the migrations a previous run generated, in place, and does not ask for confirmation. Without either, the command asks before it writes; under `--no-interaction` it never asks and proceeds.

```sh
php artisan acl:setup --pretend
php artisan acl:setup --force
```

When the permission config is not published, an interactive run also offers to publish it (`vendor:publish --tag=permission-config`). Set `permission.teams` to `true` in the published file and run the command again, since the config is read at boot. Under `--no-interaction` it only prints the message.

`acl:status` prints the permission package state, which of teams, tenant and workspace are on, the team and workspace models, the guards, the key type of the package's tables, and whether each migration the setup generates is published or pending. It exits non-zero, with the reason printed, when the permission package is not active `acl.tenant` is on without a tenant column, or `acl.key_type` differs from the permissions table's key type, so CI can use it:

```sh
php artisan acl:status
```

`acl:make-seeder` takes the seeder's name (`AclSeeder` by default) and `--force`, which replaces a seeder that already exists.

If the permission tables already exist without team columns, run the framework's `permission:setup-teams` first. Run `php artisan migrate` afterwards.

## UUID and ULID Keys

The package's tables use the key type of the framework's permission tables, so an application whose roles and permissions use UUIDs or ULIDs needs no setup beyond [the framework's own](https://github.com/hypervel/components/blob/0.4/src/docs/permission.md#uuid-and-ulid-keys). `acl.key_type` is `null` by default: the `acl_teams`, `acl_organizations` and workspace migrations copy the type of the permissions table's `id` column when the table exists, and otherwise the type of the key the configured `permission.models.role` generates (`HasUuids` or `HasUlids`). Set it to `id`, `uuid` or `ulid` to choose the type yourself, before you run the migrations.

Every model that receives assignments must use the same key type as the morph key columns of the permission tables (`permission.column_names.model_morph_key`). The `acl_organizations` table takes its user key from the user model of the first guard in `acl.guards`, so mixing integer and UUID users in one table breaks lookups on PostgreSQL, MySQL and MariaDB. A team model of your own keeps its own key type, and the setup command's migrations match it.
