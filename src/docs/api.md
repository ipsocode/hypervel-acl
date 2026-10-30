# The management API

Set `acl.routes.enabled` to mount the role, permission and team controllers. Each route requires the permission named after it (`acl.roles.update`), set in `acl.routes.permissions`.

| Route | Does |
|---|---|
| `roles`, `permissions` | CRUD; `PUT roles/{id}/permissions` syncs allowed and denied permissions |
| `GET/POST teams`, `GET/PUT/DELETE teams/{id}` | CRUD, limited to the current team's tenant |
| `PUT teams/{id}/roles` | Replace the team's roles; its members are rebuilt |
| `GET/POST teams/{id}/users`, `DELETE teams/{id}/users/{user}` | List, add and remove members; 422 when the user is in another team of the tenant |

Roles made through the API are global.

To manage roles, teams and workspaces from a browser, enable [the management UI](ui.md); it carries its own copy of these routes, so this API can stay off.
