<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Http\Middleware;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tenant;
use Symfony\Component\HttpFoundation\Response;

class ResolveAcl
{
    /**
     * The session entry holding the team the user's roles were last built from, and when.
     */
    public const SESSION_KEY = 'acl:synced';

    public function __construct(protected PermissionRegistrar $registrar, protected AccessSync $sync)
    {
    }

    /**
     * Set the request's tenant and current team, and bring the user's roles up to date with the team.
     *
     * A signed-in user acts in their tenant, when the user model has HasTenant, and as the one
     * team of it they are a member of, else the request is refused. When the membership changed
     * after the time the session was synced at, the user's roles are rebuilt; a request without a
     * session rebuilds them every time. This does not authorize anything.
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

            $memberships = DB::table(Teams::MEMBERS_TABLE)
                ->where(Teams::userPivotKey(), $user->getKey())
                ->when($tenant !== null && Tenant::check(), fn ($query) => $query->where(
                    fn ($query) => $query->whereNull($tenant)->orWhere($tenant, Tenant::id())
                ))
                ->get([Teams::teamPivotKey() . ' as team', 'updated_at as changed']);

            abort_unless($memberships->count() === 1, 403, 'The user is not in exactly one team.');

            $membership = $memberships->first();

            $this->registrar->setPermissionsTeamId($membership->team);

            $session = $request->hasSession() ? $request->session() : null;
            $synced = $session?->get(self::SESSION_KEY);
            $at = is_array($synced) && (string) $synced['team'] === (string) $membership->team ? $synced['at'] : null;

            if ($this->sync->isStale($at, $membership->changed)) {
                $at = $this->sync->refresh($user, Teams::query()->withoutGlobalScope('acl_tenant')->whereKey($membership->team)->firstOrFail());

                $session?->put(self::SESSION_KEY, ['team' => $membership->team, 'at' => $at]);
            }
        }

        return $next($request);
    }
}
