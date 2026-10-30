<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Middleware;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Collection;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Contracts\WorkspaceResolver;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Workspace;
use Ipsocode\Acl\Workspaces\Reach;
use Ipsocode\Acl\Workspaces\RequestWorkspaceResolver;
use stdClass;
use Symfony\Component\HttpFoundation\Response;

class ResolveAcl
{
    /**
     * The session entry holding the team the user's roles were last built from, and when, per workspace.
     */
    public const SESSION_KEY = 'acl:synced';

    public function __construct(
        protected PermissionRegistrar $registrar,
        protected AccessSync $sync,
        protected Reach $reach,
        protected WorkspaceResolver $resolver,
    ) {
    }

    /**
     * Set the request's tenant, workspace and current team, and bring the user's roles up to date with the team.
     *
     * A signed-in user acts in their tenant, when the user model has HasTenant. Without workspaces
     * they act as the one team of it they are a member of, else the request is refused. With
     * workspaces on, they act in the workspace the request names, else the one of their only org
     * role, else the home workspace, as the team of the org role held there or above it; with none,
     * the request is refused. When the org role changed after the time the session was synced at,
     * the user's roles are rebuilt; a request without a session rebuilds them every time. This does
     * not authorize anything.
     */
    public function handle(Request $request, Closure $next): Response
    {
        /** @var null|Model $user */
        $user = $request->user();

        if ($user !== null) {
            if (method_exists($user, 'tenantKey')) {
                Tenant::set($user->tenantKey());
            }

            $tenant = Teams::tenantColumn();

            $memberships = DB::table(Teams::membersTable())
                ->where(Teams::userPivotKey(), $user->getKey())
                ->when($tenant !== null && Tenant::check(), fn ($query) => $query->where(
                    fn ($query) => $query->whereNull($tenant)->orWhere($tenant, Tenant::id())
                ))
                ->get([Teams::teamPivotKey() . ' as team', 'updated_at as changed', ...(Workspaces::enabled() ? [Workspaces::column() . ' as workspace'] : [])]);

            if (Workspaces::enabled()) {
                $workspace = $this->workspace($request, $memberships);
                $membership = $memberships->first(fn (stdClass $row): bool => $this->reach->covers($row->workspace, $workspace));

                Workspace::set($workspace);
            } else {
                abort_unless($memberships->count() === 1, 403, 'The user is not in exactly one team.');

                $membership = $memberships->first();
                $workspace = Workspace::id();
            }

            $this->registrar->setPermissionsTeamId($membership->team);

            $session = $request->hasSession() ? $request->session() : null;
            $synced = $session?->get(self::SESSION_KEY);
            $times = is_array($synced) ? $synced['at'] : [];
            $key = $membership->team . ':' . $workspace;

            if ($this->sync->isStale($times[$key] ?? null, $membership->changed)) {
                /** @var Model&Team $team */
                $team = Teams::query()->withoutGlobalScope('acl_tenant')->whereKey($membership->team)->firstOrFail();
                $times[$key] = $this->sync->refresh($user, $team);

                $session?->put(self::SESSION_KEY, ['team' => $membership->team, 'at' => $times]);
            }
        }

        return $next($request);
    }

    /**
     * Get the workspace the request acts in: one an org role of the user covers.
     *
     * @param Collection<int, stdClass> $memberships
     */
    protected function workspace(Request $request, Collection $memberships): int|string
    {
        abort_unless($memberships->isNotEmpty(), 403, 'The user holds no org role.');

        $workspace = $this->resolver->resolve($request) ?? ($memberships->count() === 1 ? $memberships->first()->workspace : Workspace::HOME);

        if (! $memberships->contains(fn (stdClass $row): bool => $this->reach->covers($row->workspace, $workspace))) {
            // A selection kept from before the user's org roles changed must not lock them out.
            $request->hasSession() && $request->session()->forget(RequestWorkspaceResolver::SESSION_KEY);

            abort(403, 'No org role of the user covers the workspace.');
        }

        return $workspace;
    }
}
