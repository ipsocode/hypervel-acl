<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Concerns;

use Closure;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Traits\HasRoles;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Events\OrgRoleAdding;
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Workspace;

/**
 * Makes a model a team: an organizational role whose members hold its roles under the team's id.
 *
 * @phpstan-require-implements Team
 */
trait IsTeam
{
    use HasRoles;

    /**
     * Keep the cached chains and dependents right as teams are added, moved and deleted, mark a
     * moved team's memberships and those below it changed, and release a deleted team's members.
     */
    public static function bootIsTeam(): void
    {
        static::creating(static function (self $team): void {
            $inherit = Config::teamsInheritKey();

            // With workspaces on, a team inherits its tenant's Globals team unless it says otherwise.
            if (Workspaces::enabled() && $team->getAttribute($inherit) === null && $team->getAttribute('name') !== Teams::GLOBALS) {
                $team->setAttribute($inherit, Teams::globals($team->tenant())->getKey());
            }
        });

        static::created(static function (self $team): void {
            $parent = $team->getAttribute(Config::teamsInheritKey());

            app(AccessSync::class)->forgetDependentsAbove($parent);
            DB::afterCommit(static fn () => app(AccessSync::class)->forgetDependentsAbove($parent));
        });

        static::deleting(static function (self $team): void {
            $sync = app(AccessSync::class);

            $sync->purge($team);
            $sync->forgetChains($team);
            $sync->forgetDependentsAbove($team->getAttribute(Config::teamsInheritKey()));
        });

        static::saved(static function (self $team): void {
            $column = Config::teamsInheritKey();

            if (! $team->wasChanged($column)) {
                return;
            }

            // Read before the save syncs the original: the team it inherited until now.
            $previous = $team->getOriginal($column);
            $parent = $team->getAttribute($column);

            $forget = static function () use ($team, $previous, $parent): void {
                $sync = app(AccessSync::class);

                $sync->forgetChains($team);
                $sync->forgetDependentsAbove($previous);
                $sync->forgetDependentsAbove($parent);
            };

            // After the commit too, so caches built from the old rows in between are dropped.
            $forget();

            DB::afterCommit(static function () use ($team, $forget): void {
                $forget();
                app(AccessSync::class)->markChanged($team);
            });
        });
    }

    /**
     * Get the guards the team's roles may belong to.
     *
     * @return list<string>
     */
    public function guardName(): array
    {
        return Config::guards();
    }

    /**
     * Get the team whose roles this team also holds.
     */
    public function inheritedTeam(): ?Team
    {
        $key = $this->getAttribute(Config::teamsInheritKey());

        return $key === null ? null : static::query()->whereKey($key)->first();
    }

    /**
     * Get the tenant the team belongs to, or null when acl.tenant is off.
     */
    public function tenant(): int|string|null
    {
        $column = Teams::tenantColumn();

        // Raw, so a column named like this method cannot resolve back to it.
        return $column === null ? null : ($this->getAttributes()[$column] ?? null);
    }

    /**
     * Get the names of the roles assigned to the team itself.
     *
     * @return list<string>
     */
    public function ownRoleNames(): array
    {
        /** @var list<string> $names */
        $names = $this->asOwnTeam(fn () => $this->getRoleNames()->values()->all());

        return $names;
    }

    /**
     * Get the keys of the team's members.
     *
     * @return list<int|string>
     */
    public function memberKeys(): array
    {
        /** @var list<int|string> $keys */
        $keys = DB::table(Teams::ORGANIZATIONS_TABLE)->where(Teams::teamPivotKey(), $this->getKey())->distinct()->pluck(Teams::userPivotKey())->all();

        return $keys;
    }

    /**
     * Replace the team's own roles, stored under the team's own id.
     *
     * @param list<string> $names
     */
    public function syncTeamRoles(array $names): void
    {
        $this->asOwnTeam(fn () => $this->syncRoles($names));
    }

    /**
     * Run the callback as the team itself, in its tenant and the home workspace, where its own roles are kept.
     *
     * @template TReturn
     *
     * @param Closure(): TReturn $callback
     * @return TReturn
     */
    protected function asOwnTeam(Closure $callback): mixed
    {
        $previous = getPermissionsTeamId();

        setPermissionsTeamId($this->getKey());

        try {
            return Tenant::run($this->tenant(), fn () => Workspace::run(Workspace::HOME, $callback));
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    /**
     * Add a user to the team, giving the user the team's roles.
     *
     * With workspaces on, this is an org role held in the given workspace, the home one by
     * default, applying there and beneath it.
     *
     * @throws UserAlreadyInTeam when the user is in another team of the tenant, or, with workspaces on,
     *                           holds an org role in a workspace that references the given one
     */
    public function addUser(Model $user, int|string $workspace = Workspace::HOME): void
    {
        $workspaces = Workspaces::enabled();
        $tenant = Teams::tenantColumn();

        DB::transaction(function () use ($user, $workspace, $workspaces, $tenant): void {
            // Two concurrent adds for one user would each pass the check below: the user's row serializes them.
            $user->newQuery()->whereKey($user->getKey())->lockForUpdate()->value($user->getKeyName());

            $members = DB::table(Teams::ORGANIZATIONS_TABLE);

            if ($workspaces) {
                app('events')->dispatch(new OrgRoleAdding($this, $user, $workspace));
            } elseif ((clone $members)
                // The team's own tenant decides, whichever tenant the caller is acting in.
                ->where(Teams::userPivotKey(), $user->getKey())
                ->where(Teams::teamPivotKey(), '!=', $this->getKey())
                ->when($tenant !== null, fn ($query) => $query->where($tenant, $this->tenant()))
                ->exists()) {
                throw new UserAlreadyInTeam('A user can be in only one team of a tenant.');
            }

            $members->insertOrIgnore([
                Teams::teamPivotKey() => $this->getKey(),
                Teams::userPivotKey() => $user->getKey(),
                ...($workspaces ? [Workspaces::column() => $workspace] : []),
                ...($tenant === null ? [] : [$tenant => $this->tenant()]),
                'created_at' => Date::now()->format(Teams::DATE_FORMAT),
            ]);
        });

        Workspace::run($workspace, fn () => app(AccessSync::class)->refresh($user, $this));
    }

    /**
     * Remove a user from the team, taking the team's roles away; with workspaces on, only the
     * org role held in the given workspace, or all of the team's when none is given.
     */
    public function removeUser(Model $user, int|string|null $workspace = null): void
    {
        $members = DB::table(Teams::ORGANIZATIONS_TABLE)->where(Teams::teamPivotKey(), $this->getKey())->where(Teams::userPivotKey(), $user->getKey());

        (clone $members)->when($workspace !== null, fn ($query) => $query->where(Workspaces::column(), $workspace))->delete();

        app(AccessSync::class)->releaseMember($user, $this);

        // The user's roles under the team are gone in every workspace, so what remains is rebuilt.
        $members->update(['updated_at' => Date::now()->format(Teams::DATE_FORMAT)]);
    }
}
