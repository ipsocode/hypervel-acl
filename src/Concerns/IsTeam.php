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
use Ipsocode\Acl\Exceptions\UserAlreadyInTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tenant;

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
        static::created(static function (self $team): void {
            $parent = $team->getAttribute(config('acl.column_names.teams_inherit_key'));

            app(AccessSync::class)->forgetDependentsAbove($parent);
            DB::afterCommit(static fn () => app(AccessSync::class)->forgetDependentsAbove($parent));
        });

        static::deleting(static function (self $team): void {
            $sync = app(AccessSync::class);

            $sync->purge($team);
            $sync->forgetChains($team);
            $sync->forgetDependentsAbove($team->getAttribute(config('acl.column_names.teams_inherit_key')));
        });

        static::saved(static function (self $team): void {
            $column = config('acl.column_names.teams_inherit_key');

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
        return config('acl.guards');
    }

    /**
     * Get the team whose roles this team also holds.
     */
    public function inheritedTeam(): ?Team
    {
        $key = $this->getAttribute(config('acl.column_names.teams_inherit_key'));

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
        $keys = DB::table(Teams::MEMBERS_TABLE)->where(Teams::teamPivotKey(), $this->getKey())->pluck(Teams::userPivotKey())->all();

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
     * Run the callback as the team itself, in its tenant, where its own roles are kept.
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
            return Tenant::run($this->tenant(), $callback);
        } finally {
            setPermissionsTeamId($previous);
        }
    }

    /**
     * Add a user to the team, giving the user the team's roles.
     *
     * @throws UserAlreadyInTeam when the user is in another team of the tenant
     */
    public function addUser(Model $user): void
    {
        $tenant = Teams::tenantColumn();
        $members = DB::table(Teams::MEMBERS_TABLE);

        // The team's own tenant decides, whichever tenant the caller is acting in.
        if ((clone $members)
            ->where(Teams::userPivotKey(), $user->getKey())
            ->where(Teams::teamPivotKey(), '!=', $this->getKey())
            ->when($tenant !== null, fn ($query) => $query->where($tenant, $this->tenant()))
            ->exists()) {
            throw new UserAlreadyInTeam('A user can be in only one team of a tenant.');
        }

        $members->insertOrIgnore([
            Teams::teamPivotKey() => $this->getKey(),
            Teams::userPivotKey() => $user->getKey(),
            ...($tenant === null ? [] : [$tenant => $this->tenant()]),
            'created_at' => Date::now()->format(Teams::DATE_FORMAT),
        ]);

        app(AccessSync::class)->refresh($user, $this);
    }

    /**
     * Remove a user from the team, taking the team's roles away.
     */
    public function removeUser(Model $user): void
    {
        DB::table(Teams::MEMBERS_TABLE)->where(Teams::teamPivotKey(), $this->getKey())->where(Teams::userPivotKey(), $user->getKey())->delete();

        app(AccessSync::class)->rebuildMember($user, $this, remove: true);
    }
}
