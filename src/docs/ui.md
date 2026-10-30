# The management UI

The package ships screens for roles, permissions, teams, members and, with workspaces on, workspaces and their reach. They call the same controllers as [the management API](api.md), through an internal API of their own under the UI's path, so the public API can stay off or token-only.

## Enabling the UI

Set `acl.ui.enabled` (or `ACL_UI_ENABLED`) and choose a frontend:

```php
'ui' => [
    'enabled' => true,
    'frontend' => 'bundled', // 'bundled', 'react' or null
    'domain' => null,
    'path' => 'acl-admin',
    'middleware' => ['web', 'auth'],
    'permission' => 'acl.ui',
],
```

The UI is then served at `/acl-admin`. Keep `path` apart from the API's `acl.routes.prefix` (`acl`): the UI answers every URL beneath its path.

The user must be signed in through the session middleware you list, belong to a team, and hold the `acl.ui` permission, which `acl.*` also covers. Each screen's calls require the permission of the route they use (`acl.roles.update`, and so on), as set in `acl.routes.permissions`, so a user may be let into the UI and still only see what their roles allow.

The UI answers on a subdomain when `acl.ui.domain` is set, and on the application's own domain when it is `null`.

## Choosing a frontend

| `frontend` | Serves | Needs |
|---|---|---|
| `bundled` | The package's React app, prebuilt, in a Blade page | Nothing but the published assets |
| `react` | The same pages as Inertia pages, inside your application's layout | An application with Inertia, React and Tailwind, such as the React starter kit |
| `null` | No UI: the API only | |

### The bundled frontend

Publish the assets once, and again after updating the package:

```bash
php artisan vendor:publish --tag=acl-assets --force
```

`php artisan acl:setup` publishes them for you when the UI is enabled with the bundled frontend. The page loads `public/vendor/acl/app.js` and `app.css` with a version taken from a hash of the package's own bundle, so browsers fetch the new files after an update.

### The Inertia frontend

Publish the pages into your application:

```bash
php artisan vendor:publish --tag=acl-react
```

They land in `resources/js/pages/acl/`, and the controller renders the Inertia page `acl/index` with the UI's settings as its props. Edit them freely: they use only React and Tailwind classes, with no component library, so they build in an application unchanged. Tailwind generates the classes of the published files because they sit in `resources/js`.

The pages call `{path}/acl-api`, and send the `X-XSRF-TOKEN` cookie value, so the application's `web` middleware must be among `acl.ui.middleware`.

## Workspaces

With `acl.workspace` on, the header shows the workspace the session acts in. Leave it empty for the default, or enter a workspace key: the UI sends it as the `X-Workspace` header, which the workspace resolver reads. The Workspaces screen lists the tree beneath that workspace and, when you use the package's workspace model, creates, moves and deletes workspaces.

## Rebuilding the bundle

The sources are in `resources/js`. To change them in a checkout of the package:

```bash
npm ci
npm run build
npm test
```

`dist/` is committed.
