<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Workspaces;

use Hypervel\Contracts\Cache\Repository as CacheRepository;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspace;

/**
 * Where an org role applies: the workspace it is held in and every workspace beneath it, the
 * home workspace being above all. A workspace's ancestors are cached until the tree changes.
 */
class Reach
{
    public const CACHE_KEY_PREFIX = 'acl:workspace-ancestors:';

    public function __construct(protected CacheRepository $cache, protected AccessSync $sync)
    {
    }

    /**
     * Get the keys of a workspace and of the ones above it, nearest first.
     *
     * @return list<int|string>
     */
    public function ancestorsOf(int|string $workspace): array
    {
        /** @var list<int|string> $ancestors */
        $ancestors = $this->cache->rememberForever(self::CACHE_KEY_PREFIX . $workspace, fn (): array => Workspaces::ancestorKeys($workspace));

        return $ancestors;
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
            $query = Workspaces::query();

            return [Workspace::HOME, ...array_values($query->pluck($query->getModel()->getKeyName())->all())];
        }

        return [$workspace, ...Workspaces::descendantKeys([$workspace])];
    }

    /**
     * Forget the cached ancestors of a workspace and of the ones beneath it, and for a move mark
     * the teams holding org roles around it as changed: their members' roles there are stale.
     *
     * @param AclWorkspace $workspace
     */
    public function workspaceChanged(Model $workspace, bool $moved): void
    {
        $column = Workspaces::parentColumn();
        $keys = [
            ...Workspaces::ancestorKeys($workspace->getKey()),
            ...($workspace->getAttribute($column) === null ? [] : Workspaces::ancestorKeys($workspace->getAttribute($column))),
            ...($workspace->getOriginal($column) === null ? [] : Workspaces::ancestorKeys($workspace->getOriginal($column))),
        ];

        foreach ([$workspace->getKey(), ...Workspaces::descendantKeys([$workspace->getKey()])] as $key) {
            $this->cache->forget(self::CACHE_KEY_PREFIX . $key);
        }

        $this->affect(array_values(array_unique($keys)), $moved);
    }

    /**
     * Mark the teams holding org roles around a workspace about to be deleted as changed, and
     * delete its org roles, roles, permissions and assignments.
     *
     * @param AclWorkspace $workspace
     */
    public function workspaceRemoved(Model $workspace): void
    {
        $column = Workspaces::column();

        $this->affect(Workspaces::ancestorKeys($workspace->getKey()), true);

        $this->cache->forget(self::CACHE_KEY_PREFIX . $workspace->getKey());

        // The assignments go first, as raw rows are not cascaded to by the roles' deletion on every database.
        foreach ([Teams::WORKSPACE_MEMBERS_TABLE, Config::modelHasRolesTable(), Config::modelHasPermissionsTable(), Config::roleHasPermissionsTable(), Config::rolesTable(), Config::permissionsTable()] as $table) {
            DB::table($table)->where($column, $workspace->getKey())->delete();
        }
    }

    /**
     * Mark the teams holding an org role in any of the workspaces as changed.
     *
     * @param list<int|string> $workspaces
     */
    protected function affect(array $workspaces, bool $markChanged): void
    {
        if (! $markChanged) {
            return;
        }

        $teams = DB::table(Teams::WORKSPACE_MEMBERS_TABLE)->whereIn(Workspaces::column(), $workspaces)->distinct()->pluck(Teams::teamPivotKey())->all();

        foreach (Teams::query()->withoutGlobalScope('acl_tenant')->whereKey($teams)->get() as $team) {
            $this->sync->markChanged($team);
        }
    }
}
