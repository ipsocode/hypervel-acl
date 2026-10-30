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

Set `acl.workspace` to `true` and name the partition column in `acl.column_names.workspace`. Run `php artisan acl:setup` to generate the migration that adds the column to the permission tables; it puts the column first in their keys and gives existing rows the home workspace.

Workspaces are flat by default: your application identifies them itself, and an org role applies only in its own workspace. To arrange them as a tree, set `acl.models.workspace` to `Ipsocode\Acl\Models\AclWorkspace`, or to your own model with a parent column (`parent_id`, or the name in `acl.column_names.workspace_parent`). An org role then also applies in every workspace beneath its own.

With workspaces on, org roles are kept in the `acl_team_workspace_user` table instead of `acl_team_user`.

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

When the [management API](api.md) is enabled, `GET acl/reach` is available, and with a workspace model, `acl/workspaces` (index, store, show, update, destroy). Adding a user to a team takes an optional `workspace`, and removing one takes an optional `?workspace=`. They are limited to the workspace the request acts in and beneath it. A move that would put a workspace beneath itself is rejected, and deleting a workspace that has others beneath it returns a 409.
