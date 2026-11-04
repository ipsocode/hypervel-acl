# Teams

A team is an organizational role: one set of the global roles, held by every member of the team. Membership is kept in the `acl_organizations` table, whose `team_id` and `user_id` columns you may rename: the team column is the framework's `permission.column_names.team_foreign_key`, and the user column is `acl.column_names.user_pivot_key`. The package uses the framework's teams feature to give members the team's roles as assignments with `team_id = T`, and checks see the roles of the current team only.

```php
$ops = AclTeam::create(['name' => 'Ops']);

$ops->syncTeamRoles(['editor']);
$ops->addUser($user); // the user holds editor, under Ops

$ops->removeUser($user);
```

Use `Ipsocode\Acl\Models\AclTeam`, or add `IsTeam` to your own model (and implement `Ipsocode\Acl\Contracts\Team`). A team needs no roles to have members: a member of a team without roles is in the team and holds nothing.

## Keeping members up to date

A change to a team's roles applies to the team and to every team that inherits it, in bulk:

- the members' roles under those teams are deleted, so a removed role is gone at once;
- `updated_at` is set on those teams and, to the millisecond, on their `acl_organizations` rows;
- the permission cache of each affected tenant is cleared.

When `ResolveAcl` builds a member's roles, it stores the time in the member's session. On the member's next request, a membership updated at or after that time is rebuilt for that one member. A team with many members is never rebuilt all at once.

A request without a session, such as an API request authenticated by token, keeps the time in the cache for a day, per user, team and workspace, so its roles are rebuilt only after the membership changed or the cached time expired. A check made outside a request, such as in a queued job, finds no team roles for a member who has not made a request since the change. Call `AccessSync::refresh($user, $team)` first when it needs them.

## Tenants

Set `acl.tenant` to `true` to keep each tenant's roles, permissions and teams apart. The setup command then adds a tenant column, named by `acl.column_names.tenant_foreign_key`, to the five permission tables, the team table and the org role table, and puts the tenant first in the unique keys of role and permission names and of a user's org role, so two tenants may each have a role of the same name. The tenant column is required wherever the package stamps it, so enable `acl.tenant` before anything is seeded: the migration that adds the column to an existing table fails while the table has rows, which would have no tenant. If you enabled tenants on an earlier version, run `php artisan acl:setup` and `php artisan migrate` again: the `lead_tenant_unique_keys` migration it adds gives the keys to an install that already ran the tenant migration. An install that ran the tenant migrations of an earlier version keeps its tenant columns nullable. Its rows with no tenant, such as roles seeded outside a tenant, are no longer seen by any tenant, and org roles with no tenant no longer count. Give each of those rows its tenant, or delete it, before upgrading. Point `permission.models.role` and `permission.models.permission` at the package's `Ipsocode\Acl\Models\Role` and `Ipsocode\Acl\Models\Permission`, or add the `BelongsToTenant` trait to your own models.

The current tenant lives in `Ipsocode\Acl\Tenant`, for the current request or job only:

```php
Tenant::run('acme', function () {
    Role::create(['name' => 'auditor']); // belongs to acme
});
```

Models with `BelongsToTenant` see the current tenant's rows only, and new models are stamped with the current tenant. No row is shared between tenants: creating a role, permission, team or workspace with no tenant, given or current, throws a `LogicException`, and so does seeding outside a tenant, so seed each tenant's catalog in its own tenant. The package also keys the permission cache by tenant, so each tenant checks against its own roles and permissions.

Add the `HasTenant` trait to your user model, and `ResolveAcl` sets the user's tenant on each request. By default it reads the user's tenant column; override `tenantKey()` to take the tenant from somewhere else. With `acl.tenant` on, a request never runs unscoped: `ResolveAcl` throws a `LogicException` for a signed-in user whose model does not use `HasTenant`, and refuses a user whose tenant is `null` with 403.

Nothing sets the tenant for you outside a request. A queued job or a code path that runs on its own has to wrap its work in `Tenant::run()`, and a console command takes the tenant from its `--tenant` option (see [The current team](#the-current-team)). Creating a model with no tenant throws a `LogicException` that says so.

A user is in one team per tenant. With `acl.tenant` off, all teams are one tenant. `addUser()` throws `UserAlreadyInTeam` for a second team in the tenant. Remove the user from the first team to move them.

## Inheritance

A team holds the roles of the team named by its `inherit_id`, which is a row of the same table, and of that team's own inherited team, and so on. Cycles are cut. Changing a team's roles or its `inherit_id` marks that team and every team that inherits it changed, as above. Checks never walk the chain. Rebuilds read it from a cached list of each team's inherited team keys, and marking a team's memberships reaches the teams below it through a cached list of their keys. Both are kept right as teams are created, moved and deleted through the model. A raw query that changes `inherit_id` skips this, so call `AccessSync::forgetChains()` for the moved team and `AccessSync::forgetDependentsAbove()` with its old and new inherited team's keys.

## The current team

`ResolveAcl` sets the current tenant and team for a request. A signed-in user must be in exactly one team of their tenant, else the request is refused with 403. Every console command accepts `--tenant=<id>` and `--team=<id>`, which select the tenant and the team the command runs in. `--team` also selects the team's own tenant, so `--team` alone is enough for a command to run in the right tenant. When both are given and the team belongs to another tenant, the command fails with an `InvalidArgumentException` before it runs, so a team is never used under a tenant it is not in. A command run with neither option has no tenant, and with `acl.tenant` on it throws a `LogicException` where it needs one.

```bash
php artisan db:seed --class=AclSeeder --tenant=acme
php artisan db:seed --class=AclSeeder --team=7
```
