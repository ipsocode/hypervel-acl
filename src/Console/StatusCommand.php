<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

use Hypervel\Console\Command;
use Ipsocode\Acl\Console\Concerns\ChecksPermissionPackage;
use Ipsocode\Acl\Console\Concerns\FindsTeamMigrations;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;

class StatusCommand extends Command
{
    use ChecksPermissionPackage;
    use FindsTeamMigrations;

    protected ?string $signature = 'acl:status';

    protected string $description = 'Show the state of the permission package, the enabled ACL features and the migrations';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $problems = [];

        if (($problem = $this->permissionPackageProblem()) !== null) {
            $problems[] = $problem;
        }

        if (Config::tenantEnabled() && in_array(Config::tenantForeignKey(), [null, ''], true)) {
            $problems[] = 'acl.tenant is on but acl.column_names.tenant_foreign_key names no column.';
        }

        $this->table(['Setting', 'Value'], [
            ['Permission package', $problems === [] ? 'active' : 'not active'],
            ['Teams', $this->state(config('permission.teams') === true)],
            ['Tenant', $this->state(Config::tenantEnabled())],
            ['Workspace', $this->state(Workspaces::enabled())],
            ['Team model', Teams::model()],
            ['Workspace model', Workspaces::model() ?? '-'],
            ['Guards', implode(', ', Config::guards())],
        ]);

        // The tenant column is unknown without a name, so its stub cannot be listed then.
        $stubs = Config::tenantEnabled() && Config::tenantForeignKey() === null
            ? []
            : $this->teamStubs();

        $this->table(['Migration', 'State'], [
            ['create_permission_tables', $this->published('create_permission_tables')],
            ...array_map(fn (string $stub) => [$stub, $this->published($stub)], $stubs),
        ]);

        foreach ($problems as $problem) {
            $this->error($problem);
        }

        return $problems === [] ? self::SUCCESS : self::FAILURE;
    }

    /**
     * Describe whether a feature is on.
     */
    protected function state(bool $enabled): string
    {
        return $enabled ? 'on' : 'off';
    }

    /**
     * Describe whether the migration for a stub is published or still pending.
     */
    protected function published(string $stub): string
    {
        return $this->alreadyExistingMigrations($stub) === [] ? 'pending' : 'published';
    }
}
