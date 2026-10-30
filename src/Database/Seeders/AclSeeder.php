<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Database\Seeders;

use Hypervel\Database\Seeder;
use Hypervel\Permission\PermissionRegistrar;
use Ipsocode\Acl\Contracts\RoleDefinition;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Tenant;
use Ipsocode\Acl\Workspace;

abstract class AclSeeder extends Seeder
{
    /**
     * Get the roles to seed.
     *
     * @return list<RoleDefinition>
     */
    abstract protected function definitions(): array;

    /**
     * Seed the declared roles; running it again changes nothing.
     *
     * Roles are created without a team, so every team may hold them, in each workspace of the
     * model, or the current one without a model; run it again after adding a workspace.
     */
    public function run(): void
    {
        $registrar = app(PermissionRegistrar::class);
        $previous = $registrar->getPermissionsTeamId();

        $registrar->setPermissionsTeamId(null);

        try {
            $definitions = array_values($this->definitions());

            // Roles are the same in every workspace, each partition holding its own copy.
            foreach (Workspaces::enabled() && Workspaces::model() !== null ? Workspaces::partitions() : [Workspace::id()] as $workspace) {
                Workspace::run($workspace, fn () => $this->seed($definitions));
            }
        } finally {
            $registrar->setPermissionsTeamId($previous);
        }
    }

    /**
     * Seed the roles and their permissions, and prune what no definition names.
     *
     * @param list<RoleDefinition> $definitions
     */
    protected function seed(array $definitions): void
    {
        $registrar = app(PermissionRegistrar::class);

        $wanted = [];
        foreach ($definitions as $definition) {
            foreach ([...$this->permissionsOf($definition), ...$definition->deniedPermissions()] as $name) {
                $wanted[$definition->guard() . "\0" . $name] = [$name, $definition->guard()];
            }
        }

        $existing = $registrar->getPermissionClass()::query()->get(['name', 'guard_name'])
            ->mapWithKeys(fn ($permission) => [$permission->guard_name . "\0" . $permission->name => true])
            ->all();

        // A raw insert skips the tenant and partition stamping, so the current ones are written here.
        $column = Teams::tenantColumn();
        $tenant = [
            ...($column === null ? [] : [$column => Tenant::id()]),
            ...(Workspaces::enabled() ? [Workspaces::column() => Workspace::id()] : []),
        ];

        $now = now();
        $missing = [];
        foreach (array_diff_key($wanted, $existing) as [$name, $guard]) {
            $missing[] = [...$tenant, 'name' => $name, 'guard_name' => $guard, 'created_at' => $now, 'updated_at' => $now];
        }

        if ($missing !== []) {
            $registrar->getPermissionClass()::insert($missing);
        }

        $registrar->forgetCachedPermissions();

        foreach ($definitions as $definition) {
            $registrar->getRoleClass()::findOrCreate($definition, $definition->guard())->syncPermissionEffects(
                allowed: $this->permissionsOf($definition),
                denied: $definition->deniedPermissions(),
            );
        }

        $registrar->forgetCachedPermissions();

        $this->prune($definitions, $wanted);

        $registrar->forgetCachedPermissions();
    }

    /**
     * Get a role's permissions, including those of the role it mirrors.
     *
     * @return list<string>
     */
    protected function permissionsOf(RoleDefinition $definition): array
    {
        $permissions = $definition->permissions();

        for ($mirror = $definition->mirrors(), $depth = 0; $mirror !== null && $depth < 16; $mirror = $mirror->mirrors(), $depth++) {
            $permissions = [...$permissions, ...$mirror->permissions()];
        }

        return array_values(array_unique($permissions));
    }

    /**
     * Delete the roles and permissions no definition names, among those the seeder owns: the
     * current tenant's, or the shared ones without a tenant.
     *
     * @param list<RoleDefinition> $definitions
     * @param array<string, array{string, string}> $wanted
     */
    protected function prune(array $definitions, array $wanted): void
    {
        $roles = array_map(fn (RoleDefinition $definition) => $definition->value, $definitions);
        $registrar = app(PermissionRegistrar::class);

        $column = Teams::tenantColumn();
        $owned = fn ($query) => $column === null ? $query : $query->where($column, Tenant::id());

        $owned($registrar->getRoleClass()::query())->whereNull($registrar->teamsKey)->whereNotIn('name', $roles)->get()->each->delete();

        $owned($registrar->getPermissionClass()::query())->get()
            ->reject(fn ($permission) => isset($wanted[$permission->guard_name . "\0" . $permission->name]))
            ->each->delete();
    }
}
