<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Workspaces;

use Closure;
use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Database\Eloquent\Builder;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Workspace;

/**
 * Where an org role applies: the workspace it is held in and every workspace beneath it, the
 * home workspace being above all. A workspace's tenant and ancestors are cached until the tree changes.
 */
class Reach
{
    public const CACHE_KEY_PREFIX = 'acl:workspace-entry:';

    public function __construct(protected CacheRepository $cache, protected AccessSync $sync, protected PermissionRegistrar $registrar)
    {
    }

    /**
     * Get the keys of a workspace and of the ones above it, nearest first.
     *
     * @return list<int|string>
     */
    public function ancestorsOf(int|string $workspace): array
    {
        return $this->entry($workspace)['ancestors'] ?? [$workspace];
    }

    /**
     * Determine whether the workspace exists for the current tenant: the home one always, and one
     * the application identifies itself, which has no table to check, as far as it can be told.
     */
    public function exists(int|string $workspace): bool
    {
        if ((string) $workspace === (string) Workspace::HOME || Workspaces::model() === null) {
            return true;
        }

        $entry = $this->entry($workspace);

        return $entry !== null && ($entry['tenant'] === null || Tenant::id() === null || (string) $entry['tenant'] === (string) Tenant::id());
    }

    /**
     * Determine whether the workspace exists and the one the request acts in covers it.
     */
    public function reaches(int|string $workspace): bool
    {
        return $this->covers(Workspace::id(), $workspace) && $this->exists($workspace);
    }

    /**
     * Determine whether an org role held in one workspace applies in another.
     */
    public function covers(int|string $held, int|string $workspace): bool
    {
        return (string) $held === (string) Workspace::HOME
            || in_array((string) $held, array_map('strval', $this->ancestorsOf($workspace)), true);
    }

    /**
     * Get the keys of a workspace and of the ones beneath it, the home workspace covering the whole tree.
     *
     * @return list<int|string>
     */
    public function beneath(int|string $workspace): array
    {
        if ((string) $workspace === (string) Workspace::HOME) {
            return Workspaces::partitions();
        }

        return [$workspace, ...Workspaces::descendantKeys([$workspace])];
    }

    /**
     * Limit a workspace query to the workspace the request acts in and the ones beneath it; the
     * home workspace covers the whole tree, so its query is left as it is.
     *
     * @param Builder<Model> $query
     * @return Builder<Model>
     */
    public function within(Builder $query): Builder
    {
        return (string) Workspace::id() === (string) Workspace::HOME ? $query : $query->whereKey($this->beneath(Workspace::id()));
    }

    /**
     * Forget the cached ancestors of a workspace and of the ones beneath it after it moved, and
     * mark the teams holding org roles around it as changed: their members' roles there are stale.
     *
     * @param AclWorkspace $workspace
     */
    public function workspaceChanged(Model $workspace): void
    {
        $column = Workspaces::parentColumn();
        $keys = [
            ...Workspaces::ancestorKeys($workspace->getKey()),
            ...($workspace->getAttribute($column) === null ? [] : Workspaces::ancestorKeys($workspace->getAttribute($column))),
            ...($workspace->getOriginal($column) === null ? [] : Workspaces::ancestorKeys($workspace->getOriginal($column))),
        ];

        $this->forget([$workspace->getKey(), ...Workspaces::descendantKeys([$workspace->getKey()])]);

        $this->affect(array_values(array_unique($keys)));
    }

    /**
     * Mark the teams holding org roles around a workspace about to be deleted as changed, and
     * delete its org roles, roles, permissions and assignments, and forget what was cached for them.
     *
     * Run it inside the delete's transaction: the workspace may still refuse to go.
     *
     * @param AclWorkspace $workspace
     */
    public function workspaceRemoved(Model $workspace): void
    {
        $column = Workspaces::column();

        $this->affect(Workspaces::ancestorKeys($workspace->getKey()));

        $this->forget([$workspace->getKey()]);

        // The assignments go first, as raw rows are not cascaded to by the roles' deletion on every database.
        foreach ([Teams::ORGANIZATIONS_TABLE, Config::modelHasRolesTable(), Config::modelHasPermissionsTable(), Config::roleHasPermissionsTable(), Config::rolesTable(), Config::permissionsTable()] as $table) {
            DB::table($table)->where($column, $workspace->getKey())->delete();
        }

        $tenant = Teams::tenantColumn();

        $this->afterCommit(fn () => Tenant::run($tenant === null ? null : $workspace->getAttribute($tenant), fn () => Workspace::run($workspace->getKey(), fn () => $this->registrar->forgetCachedPermissions())));
    }

    /**
     * Get what is cached of a workspace, or null when it does not exist.
     *
     * Only a workspace that exists is kept: its key may come from a request.
     *
     * @return null|array{tenant: null|int|string, ancestors: list<int|string>}
     */
    protected function entry(int|string $workspace): ?array
    {
        if ((string) $workspace === (string) Workspace::HOME || Workspaces::model() === null) {
            return null;
        }

        /** @var null|array{tenant: null|int|string, ancestors: list<int|string>} $entry */
        $entry = $this->cache->get(self::CACHE_KEY_PREFIX . $workspace);

        if ($entry !== null) {
            return $entry;
        }

        $model = Workspaces::query()->withoutGlobalScope('acl_tenant')->whereKey($workspace)->first();

        if ($model === null) {
            return null;
        }

        $column = Teams::tenantColumn();
        $entry = ['tenant' => $column === null ? null : $model->getAttribute($column), 'ancestors' => Workspaces::ancestorKeys($workspace)];

        $this->cache->forever(self::CACHE_KEY_PREFIX . $workspace, $entry);

        return $entry;
    }

    /**
     * Forget the cached entries of the workspaces, again once the transaction they changed in is
     * committed: a request in between may have cached the old tree.
     *
     * @param list<int|string> $workspaces
     */
    protected function forget(array $workspaces): void
    {
        $this->afterCommit(function () use ($workspaces): void {
            foreach ($workspaces as $key) {
                $this->cache->forget(self::CACHE_KEY_PREFIX . $key);
            }
        });
    }

    /**
     * Run the callback now and again after the surrounding transaction commits.
     *
     * @param Closure(): mixed $callback
     */
    protected function afterCommit(Closure $callback): void
    {
        $callback();

        DB::afterCommit($callback);
    }

    /**
     * Mark the teams holding an org role in any of the workspaces as changed.
     *
     * @param list<int|string> $workspaces
     */
    protected function affect(array $workspaces): void
    {
        $teams = DB::table(Teams::ORGANIZATIONS_TABLE)->whereIn(Workspaces::column(), $workspaces)->distinct()->pluck(Teams::teamPivotKey())->all();

        $this->sync->markChangedMany(Teams::query()->withoutGlobalScope('acl_tenant')->whereKey($teams)->get());
    }
}
