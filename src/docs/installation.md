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
- The permission package has teams off: set `permission.teams` to `true`, then run `php artisan permission:setup-teams` if the permission tables already exist.
- The permission tables are not migrated: run `php artisan acl:setup`, then `php artisan migrate`. `acl:setup` itself skips this check, since it publishes the migration.

`acl:setup` takes two options. `--pretend` lists the migrations it would write and writes nothing, not even the bundled UI's assets. `--force` overwrites the migrations a previous run generated, in place, and does not ask for confirmation. Without either, the command asks before it writes; under `--no-interaction` it never asks and proceeds.

```sh
php artisan acl:setup --pretend
php artisan acl:setup --force
```

An interactive `acl:*` command also offers to take the missing step itself. When `config/permission.php` is not published, it offers to publish it (`vendor:publish --tag=permission-config`); when teams are off, it offers to set `'teams' => true` in that file, and tells you to set it yourself when the file holds another value. Run the command again afterwards, since the config is read at boot. When the permission tables are not migrated, it offers to run `acl:setup`, which publishes the permission migration with the package's own; run `php artisan migrate` and then the command again. Under `--no-interaction` or `--pretend` it never asks, and only prints the message.

`acl:status` prints the permission package state, which of teams, tenant and workspace are on, the team and workspace models, the guards, the users table, the key type of the package's tables, and whether each migration the setup generates is published or pending. It exits non-zero, with the reason printed, when the permission package is not active, `acl.tenant` is on without a tenant column, the users table is missing or its key type differs from the user model's, or `acl.key_type` differs from the permissions table's key type, so CI can use it:

```sh
php artisan acl:status
```

`--only` limits the report to a feature, `tenant` or `workspace`, and may be given more than once. A limited report keeps the permission package line and its problem, and otherwise shows only the settings, migrations and problems of the features it names, so a CI step can check one feature alone. The option is not called `--workspace`, which every command already takes to name the workspace it runs in:

```sh
php artisan acl:status --only=tenant
php artisan acl:status --only=tenant --only=workspace
```

`acl:make-seeder` takes the seeder's name (`AclSeeder` by default) and `--force`, which replaces a seeder that already exists.

If the permission tables already exist without team columns, set `permission.teams` to `true` and run the framework's `permission:setup-teams` first. Run `php artisan migrate` afterwards.

## UUID and ULID Keys

The package's tables use the key type of the framework's permission tables, so an application whose roles and permissions use UUIDs or ULIDs needs no setup beyond [the framework's own](https://github.com/hypervel/components/blob/0.4/src/docs/permission.md#uuid-and-ulid-keys). `acl.key_type` is `null` by default: the `acl_teams`, `acl_organizations` and workspace migrations copy the type of the permissions table's `id` column when the table exists, and otherwise the type of the key the configured `permission.models.role` generates (`HasUuids` or `HasUlids`), or, when that model names none, the type the first `acl.guards` guard's user model generates. The package's `Role` and `Permission` models, which cannot afford a table lookup on every instance, use the same fallback without the table. Set it to `id`, `uuid` or `ulid` to choose the type yourself, before you run the migrations. When `acl.key_type` is `null` and the permission tables are not migrated yet, an interactive `acl:setup` asks which type to use and sets the answer as `key_type` in your `config/acl.php` (publishing it first if needed); it never asks under `--no-interaction` or `--pretend`. The package's `Role` and `Permission` models follow `acl.key_type` too, so with it set they generate UUIDs or ULIDs; add `HasUuids` or `HasUlids` to your own models instead if you leave it `null`.

Every model that receives assignments must use the same key type as the morph key columns of the permission tables (`permission.column_names.model_morph_key`). The `acl_organizations` table takes its user key from the user model of the first guard in `acl.guards`, so mixing integer and UUID users in one table breaks lookups on PostgreSQL, MySQL and MariaDB. A team model of your own keeps its own key type, and the setup command's migrations match it.
