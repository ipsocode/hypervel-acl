<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Listeners;

use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Events\OrgRoleAdding;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;

class EnsureOrgRolesAreUnrelated
{
    /**
     * Refuse an org role whose workspace is the same as, above or beneath a workspace the user
     * already holds one in, whatever the team. The home workspace is above every other.
     *
     * @throws UserAlreadyInTeam
     */
    public function handle(OrgRoleAdding $event): void
    {
        $tenant = Teams::tenantColumn();

        $held = DB::table(Teams::WORKSPACE_MEMBERS_TABLE)
            ->where(Teams::userPivotKey(), $event->user->getKey())
            ->when($tenant !== null, fn ($query) => $query->where($tenant, $event->team->tenant()))
            // The same team in the same workspace is added again, not a second org role.
            ->whereNot(fn ($query) => $query
                ->where(Teams::teamPivotKey(), $event->team->getKey())
                ->where(Workspaces::column(), $event->workspace))
            ->pluck(Workspaces::column())
            ->map(fn (int|string $workspace): string => (string) $workspace)
            ->all();

        $related = [
            (string) Workspace::HOME,
            ...array_map('strval', Workspaces::ancestorKeys($event->workspace)),
            ...array_map('strval', Workspaces::descendantKeys([$event->workspace])),
        ];

        if ((string) $event->workspace === (string) Workspace::HOME ? $held !== [] : array_intersect($held, $related) !== []) {
            throw new UserAlreadyInTeam('A user cannot hold org roles in workspaces that reference each other.');
        }
    }
}
