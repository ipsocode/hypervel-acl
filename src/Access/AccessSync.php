<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Access;

use Closure;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Database\Query\Builder as QueryBuilder;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Support\Config as AclConfig;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Workspace;

class AccessSync
{
    public const CHAIN_CACHE_KEY_PREFIX = 'acl:team-chain:';

    public const DEPENDENTS_CACHE_KEY_PREFIX = 'acl:team-dependents:';

    public const SYNCED_CACHE_KEY_PREFIX = 'acl:synced:';

    /**
     * The seconds a request without a session is remembered as synced.
     */
    public const SYNCED_CACHE_TTL = 86400;

    public function __construct(protected PermissionRegistrar $registrar, protected CacheRepository $cache)
    {
    }

    /**
     * Give a user the team's effective roles, or take them away, under the team's id, in one
     * workspace: the current one unless given.
     *
     * @param Model&Team $team
     */
    public function rebuildMember(Model $user, Team $team, bool $remove = false, int|string|null $workspace = null): void
    {
        $this->asTeam($team, function () use ($user, $team, $remove): void {
            $user->syncRoles($remove ? [] : $this->rolesFor($team)); // @phpstan-ignore method.notFound (the user uses HasRoles)
        }, $workspace ?? Workspace::id());
    }

    /**
     * Take a user's roles under the team away in every workspace, in one query.
     *
     * @param Model&Team $team
     */
    public function releaseMember(Model $user, Team $team): void
    {
        $rows = DB::table(Config::modelHasRolesTable())
            ->where(Config::teamForeignKey(), $team->getKey())
            ->where('model_type', $user->getMorphClass())
            ->where(Config::morphKey(), $user->getKey());

        $partitions = $this->partitions(clone $rows);

        $rows->delete();

        $this->forgetCachedPermissions([$team->getKey()], $partitions);
    }

    /**
     * Rebuild a member from the team and return when, for the caller to compare later changes with.
     *
     * The time is taken before the roles are read: a change that lands during the rebuild is newer.
     *
     * @param Model&Team $team
     */
    public function refresh(Model $user, Team $team): string
    {
        $synced = Date::now()->format(Teams::DATE_FORMAT);

        $this->rebuildMember($user, $team);

        return $synced;
    }

    /**
     * Get when a user without a session was last rebuilt for a team and workspace, as 'team:workspace'.
     */
    public function syncedAt(Model $user, string $key): ?string
    {
        /** @var null|string $synced */
        $synced = $this->cache->get($this->syncedCacheKey($user, $key));

        return $synced;
    }

    /**
     * Remember when a user without a session was rebuilt, so only a change to the membership rebuilds it again.
     */
    public function rememberSynced(Model $user, string $key, string $synced): void
    {
        $this->cache->put($this->syncedCacheKey($user, $key), $synced, self::SYNCED_CACHE_TTL);
    }

    /**
     * Return the cache key of a user's last sync time for a membership.
     */
    protected function syncedCacheKey(Model $user, string $key): string
    {
        return self::SYNCED_CACHE_KEY_PREFIX . $user->getMorphClass() . ':' . $user->getKey() . ':' . $key;
    }

    /**
     * Determine whether a membership changed since its member was last synced.
     *
     * Equal times count as stale, so a change and a sync in the same millisecond only cost a rebuild.
     */
    public function isStale(?string $synced, ?string $changed): bool
    {
        return $synced === null || ($changed !== null && Date::parse($synced)->lte(Date::parse($changed)));
    }

    /**
     * Mark a team, and every team that inherits it, as changed: their members' roles are deleted
     * in bulk, so a removed role is gone at once, and rebuilt on each member's next request.
     *
     * @param Model&Team $team
     */
    public function markChanged(Team $team): void
    {
        $this->markChangedMany([$team]);
    }

    /**
     * Mark several teams, and every team that inherits any of them, as changed, touching, deleting
     * and flushing once over all of them.
     *
     * @param iterable<Model&Team> $teams
     */
    public function markChangedMany(iterable $teams): void
    {
        $keys = [];
        $morphClass = null;

        foreach ($teams as $team) {
            $morphClass = $team->getMorphClass();

            foreach ([$team->getKey(), ...$this->dependents($team)] as $key) {
                $keys[(string) $key] = $key;
            }
        }

        if ($morphClass === null) {
            return;
        }

        $keys = array_values($keys);

        // The teams' own rows, which hold each team's roles, stay.
        $rows = DB::table(Config::modelHasRolesTable())
            ->whereIn(Config::teamForeignKey(), $keys)
            ->where('model_type', '!=', $morphClass);

        $partitions = $this->partitions(clone $rows);

        // The times are bumped before the roles are deleted, and in one transaction: a member who
        // reads the old time never finds the roles gone, so no session is marked fresh without them.
        DB::transaction(function () use ($keys, $rows): void {
            $this->touch($keys);

            $rows->delete();
        });

        // A member rebuilt while the roles were being deleted stored a sync time later than the first
        // one, and lost its rows to the delete: a time taken after the commit marks it stale again.
        $this->touch($keys);

        $this->forgetCachedPermissions($keys, $partitions);
    }

    /**
     * Stamp the teams and their members' org roles as changed now.
     *
     * @param list<int|string> $keys
     */
    protected function touch(array $keys): void
    {
        $model = new (Teams::model());

        DB::table(Teams::ORGANIZATIONS_TABLE)->whereIn(Teams::teamPivotKey(), $keys)->update(['updated_at' => Date::now()->format(Teams::DATE_FORMAT)]);

        // The model's own date format keeps the milliseconds a bulk touch() would round away.
        Teams::query()->withoutGlobalScope(Teams::TENANT_SCOPE)->whereKey($keys)->toBase()
            ->update([$model->getUpdatedAtColumn() => $model->freshTimestampString()]);
    }

    /**
     * Get the workspaces the rows are in, or the home one with workspaces off.
     *
     * @return list<int|string>
     */
    protected function partitions(QueryBuilder $rows): array
    {
        /** @var list<int|string> $partitions */
        $partitions = Workspaces::enabled() ? $rows->distinct()->pluck(Workspaces::column())->all() : [Workspace::HOME];

        return $partitions;
    }

    /**
     * Forget the permission cache of each tenant the given teams belong to, in each of the
     * workspaces; a raw delete skips it.
     *
     * @param list<int|string> $keys
     * @param list<int|string> $partitions
     */
    protected function forgetCachedPermissions(array $keys, array $partitions): void
    {
        $column = Teams::tenantColumn();

        $tenants = $column === null
            ? [null]
            : Teams::query()->withoutGlobalScope(Teams::TENANT_SCOPE)->whereKey($keys)->distinct()->pluck($column)->all();

        foreach ($tenants as $tenant) {
            foreach ($partitions as $partition) {
                Tenant::run($tenant, fn () => Workspace::run($partition, fn () => $this->registrar->forgetCachedPermissions()));
            }
        }
    }

    /**
     * Take every role of a team away from its members and from the team itself.
     *
     * @param Model&Team $team
     */
    public function purge(Team $team): void
    {
        $rows = DB::table(Config::modelHasRolesTable())->where(Config::teamForeignKey(), $team->getKey());

        $partitions = $this->partitions(clone $rows);

        $rows->delete();

        $this->forgetCachedPermissions([$team->getKey()], $partitions);
    }

    /**
     * Get the keys of the teams that inherit a team, however far down, stopping at a cycle.
     *
     * Cached until a team below it is added, removed or moved; see forgetDependentsAbove().
     *
     * @param Model&Team $team
     * @return list<int|string>
     */
    public function dependents(Team $team): array
    {
        /** @var list<int|string> $dependents */
        $dependents = $this->cache->rememberForever(self::DEPENDENTS_CACHE_KEY_PREFIX . $team->getKey(), function () use ($team): array {
            $column = AclConfig::teamsInheritKey();
            $seen = [(string) $team->getKey() => true];
            $queue = [$team->getKey()];
            $found = [];

            // Unscoped, so the cached list is the same whichever tenant builds it.
            while ($queue !== []) {
                foreach (Teams::query()->withoutGlobalScope(Teams::TENANT_SCOPE)->where($column, array_pop($queue))->pluck($team->getKeyName()) as $key) {
                    if (! isset($seen[(string) $key])) {
                        $seen[(string) $key] = true;
                        $queue[] = $key;
                        $found[] = $key;
                    }
                }
            }

            return $found;
        });

        return $dependents;
    }

    /**
     * Forget the cached dependents of a team and of every team it inherits: a team was added
     * below it, removed from below it, or moved.
     *
     * @param null|int|string $key the key of the team the changed team inherits, or did
     */
    public function forgetDependentsAbove(int|string|null $key): void
    {
        $team = $key === null ? null : Teams::query()->withoutGlobalScope(Teams::TENANT_SCOPE)->whereKey($key)->first();

        if ($team === null) {
            return;
        }

        foreach ($this->chainOf($team) as $above) {
            $this->cache->forget(self::DEPENDENTS_CACHE_KEY_PREFIX . $above);
        }
    }

    /**
     * Get the role names a team's members end up with.
     *
     * The team's own roles and those of the teams it inherits.
     *
     * @param Model&Team $team
     * @return list<string>
     */
    public function rolesFor(Team $team): array
    {
        $pivot = $this->registrar->pivotRole;

        // Each team's own roles are held by the team model itself, under its own id.
        $held = DB::table(Config::modelHasRolesTable())
            ->where('model_type', $team->getMorphClass())
            ->whereIn(Config::morphKey(), $this->chainOf($team))
            ->whereColumn(Config::teamForeignKey(), Config::morphKey())
            ->when(Workspaces::enabled(), fn ($query) => $query->where(Workspaces::column(), Workspace::HOME))
            ->select($pivot);

        /** @var list<string> $names */
        $names = DB::table(Config::rolesTable())->whereIn('id', $held)->distinct()->pluck('name')->all();

        return $this->existing($names, $team);
    }

    /**
     * Get the keys of a team and of the teams it inherits, however far up, stopping at a cycle.
     *
     * Cached until the team or one it inherits changes its inherited team; see forgetChains().
     *
     * @param Model&Team $team
     * @return list<int|string>
     */
    public function chainOf(Team $team): array
    {
        /** @var list<int|string> $chain */
        $chain = $this->cache->rememberForever(self::CHAIN_CACHE_KEY_PREFIX . $team->getKey(), function () use ($team): array {
            $column = AclConfig::teamsInheritKey();
            $chain = [$team->getKey()];
            $seen = [(string) $team->getKey() => true];

            // Unscoped, so the cached chain is the same whichever tenant builds it.
            for ($parent = $team->getAttribute($column); $parent !== null && ! isset($seen[(string) $parent]); $parent = Teams::query()->withoutGlobalScope(Teams::TENANT_SCOPE)->whereKey($parent)->value($column)) {
                $seen[(string) $parent] = true;
                $chain[] = $parent;
            }

            return $chain;
        });

        return $chain;
    }

    /**
     * Forget the cached chains of a team and of every team that inherits it.
     *
     * @param Model&Team $team
     */
    public function forgetChains(Team $team): void
    {
        foreach ([$team->getKey(), ...$this->dependents($team)] as $key) {
            $this->cache->forget(self::CHAIN_CACHE_KEY_PREFIX . $key);
        }
    }

    /**
     * Run the callback as the team, in the team's tenant and the given workspace.
     *
     * @param Model&Team $team
     * @param Closure(): void $callback
     */
    protected function asTeam(Team $team, Closure $callback, int|string $workspace): void
    {
        $previous = $this->registrar->getPermissionsTeamId();

        $this->registrar->setPermissionsTeamId($team->getKey());

        try {
            Tenant::run($team->tenant(), fn () => Workspace::run($workspace, $callback));
        } finally {
            $this->registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Keep the names of roles the team may hold: global ones and its own.
     *
     * @param list<string> $names
     * @param Model&Team $team
     * @return list<string>
     */
    protected function existing(array $names, Team $team): array
    {
        if ($names === []) {
            return [];
        }

        $column = $this->registrar->teamsKey;

        /** @var list<string> $existing */
        $existing = $this->registrar->getRoleClass()::query()
            ->whereIn('name', $names)
            ->where(fn ($query) => $query->whereNull($column)->orWhere($column, $team->getKey()))
            ->pluck('name')
            ->values()
            ->all();

        return $existing;
    }
}
