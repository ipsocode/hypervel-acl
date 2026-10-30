<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console\Concerns;

use Hypervel\Support\Facades\File;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use SplFileInfo;

trait FindsTeamMigrations
{
    /**
     * Get the stubs to copy: the inheritance column for the application's own team table, and,
     * with acl.tenant on, the tenant column for the permission, team and org role tables and the unique keys it leads, and, with
     * acl.workspace on, the partition column for the permission tables and, for the application's
     * own workspace model, its parent column.
     *
     * The package's team and workspace tables are migrations the package loads itself.
     *
     * @return list<string>
     */
    protected function teamStubs(): array
    {
        return [
            ...(Teams::usesPackageModel() ? [] : ['add_team_inheritance']),
            ...(Teams::tenantColumn() !== null ? ['add_tenant_column', 'lead_tenant_unique_keys'] : []),
            ...(Workspaces::enabled() ? ['add_workspace_column'] : []),
            ...(Workspaces::enabled() && Workspaces::model() !== null && ! Workspaces::usesPackageModel() ? ['add_workspace_parent'] : []),
        ];
    }

    /**
     * Get the names of the migrations already generated for a stub.
     *
     * @return array<int, string>
     */
    protected function alreadyExistingMigrations(string $stub): array
    {
        return collect($this->migrationNames())
            ->filter(fn (string $name) => preg_match('/^\d{4}_\d{2}_\d{2}_\d{6}_' . $stub . '\.php$/', $name) === 1)
            ->values()
            ->all();
    }

    /**
     * Get the file names in the application's migrations directory.
     *
     * @return array<int, string>
     */
    protected function migrationNames(): array
    {
        return array_map(fn (SplFileInfo $file) => $file->getFilename(), File::files(database_path('migrations')));
    }
}
