# Workspaces

- [Introduction](#introduction)
- [Configuration](#configuration)
- [The Home Workspace and Globals Team](#the-home-workspace-and-globals-team)
- [Org Roles](#org-roles)
- [The Current Workspace](#the-current-workspace)
- [Seeding Roles](#seeding-roles)
- [Workspaces in the API](#workspaces-in-the-api)

<a name="introduction"></a>
## Introduction

Workspaces let a team's roles apply in a part of your organization, such as a region or a branch. Each workspace is a [permission partition](https://hypervel.org/docs/permission#row-partitioning): the same user can hold different roles in each one. A user holds an org role, a team in a workspace, and may hold several as long as their workspaces do not reference each other.

Workspaces are optional. With `acl.workspace` off, nothing described here exists.

<a name="configuration"></a>
## Configuration

Set `acl.workspace` to `true` and, if you want another name than `workspace_id`, set the partition column in `acl.column_names.workspace_foreign_key`. Run `php artisan acl:setup` to generate the migration that adds the column to the permission tables and to the `acl_organizations` table; it puts the column first in their keys and gives existing rows the home workspace. This is also the path for an application migrated with workspaces off: after you set `acl.workspace`, run `acl:setup` and `migrate`, and the org roles table is rebuilt to be keyed by workspace, team and user, so a user may hold a role in each workspace. The same run adds an index on the team column of `acl_organizations`, whose primary key now leads with the workspace, so that team role changes and member lookups do not scan the table.

Workspaces are flat by default: your application identifies them itself, and an org role applies only in its own workspace. To arrange them as a tree, set `acl.models.workspace` to `Ipsocode\Acl\Models\AclWorkspace`, or to your own model with a parent column (`parent_id`, or the name in `acl.column_names.workspace_parent`). An org role then also applies in every workspace beneath its own.

Org roles are kept in the `acl_organizations` table, one row for each user, team and workspace. With workspaces off, every row is in the home workspace, and a user holds one.

> [!WARNING]
> The `acl:setup` migration rebuilds primary keys for MySQL, MariaDB and PostgreSQL. SQLite cannot drop a primary key, so create its permission tables partitioned from the start.

<a name="the-home-workspace-and-globals-team"></a>
## The Home Workspace and Globals Team

Workspace `0` is the home workspace, the default when none is set. Every team's own roles are stored there, and any member may act in it.

Each tenant has a `Globals` team, created the first time a team is. With workspaces on, a team that inherits no other team inherits `Globals`, so the roles you give it are held by everyone in the tenant. Use `Teams::globals()` to get it.

<a name="org-roles"></a>
## Org Roles

```php
$ops->addUser($user, $north->id);

$ops->removeUser($user, $north->id);
```

A user may hold several org roles, in any teams, but not in workspaces that reference each other: the same workspace, one above or beneath the other, or the home workspace together with any other. Before the org role is written, the `OrgRoleAdding` event is dispatched and the package refuses a conflict with a `UserAlreadyInTeam` exception. Adding the same team in the same workspace again changes nothing.

Moving or deleting a workspace marks the teams holding org roles around it as changed. Their members' roles are removed in every workspace at once and rebuilt the next time each member acts there.

<a name="the-current-workspace"></a>
## The Current Workspace

The `ResolveAcl` middleware sets the workspace first, then acts as the team of the org role that covers it. It uses the workspace the request names in the `X-Workspace` header or a `workspace` route parameter, which is kept in the session as the user's selection; else the selection; else the workspace of the user's only org role; else the home workspace. A workspace none of the user's org roles covers is refused with a 403, and a stale selection is forgotten when that happens. Bind your own `Ipsocode\Acl\Contracts\WorkspaceResolver` to read it from elsewhere.

Outside a request, use `Workspace::run($id, fn () => ...)`, or pass `--workspace` to an Artisan command.

<a name="seeding-roles"></a>
## Seeding Roles

Roles are the same in every workspace, but each workspace holds its own copy. With a workspace model, your `AclSeeder` seeds the home workspace and every workspace; run it again after adding one. Without a model, it seeds the current workspace, so run it inside `Workspace::run()` for each.

<a name="workspaces-in-the-api"></a>
## Workspaces in the API

When the [management API](api.md) is enabled, with a workspace model, `GET acl/reach` and `acl/workspaces` (index, store, show, update, destroy) are available. Adding a user to a team takes an optional `workspace`, and removing one takes an optional `?workspace=`. They are limited to the workspace the request acts in and beneath it: a user is added to the one the request acts in when none is given, and removed from every org role of the team within the reach when none is given, and the team's members listed are those within it. Only the home workspace may place a workspace at the top of the tree. A move is rejected when it would put a workspace beneath itself, or leave a user with org roles in workspaces that reference each other. Deleting a workspace that has others beneath it returns a 409.

The workspace endpoints read and write the `name`, `kind` and `meta` columns, so a workspace model of your own needs them to use the API.
