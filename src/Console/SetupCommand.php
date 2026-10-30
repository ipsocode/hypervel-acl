<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console;

use Hypervel\Console\Command;
use Hypervel\Permission\Support\Config as PermissionConfig;
use Hypervel\Support\Facades\Date;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Console\Concerns\ChecksPermissionPackage;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Frontend;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use SplFileInfo;

class SetupCommand extends Command
{
    use ChecksPermissionPackage;

    protected ?string $signature = 'acl:setup {--force : Overwrite the migrations already generated} {--pretend : List the migrations without writing them}';

    protected string $description = 'Generate the migrations the permission tables and teams need, and publish the bundled UI assets';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        if (($problem = $this->permissionPackageProblem(migrated: false)) !== null) {
            $this->error($problem);

            return self::FAILURE;
        }

        $force = $this->option('force');
        $pretend = $this->option('pretend');

        $teamKey = PermissionConfig::teamForeignKey();
        $roles = config('permission.table_names.roles');

        if (Schema::hasTable($roles) && ! Schema::hasColumn($roles, $teamKey)) {
            $this->error('The permission tables have no team columns: run permission:setup-teams and migrate first.');

            return self::FAILURE;
        }

        // The bundled UI's assets are refreshed on every run: a package update changes them.
        if (! $pretend && Config::uiEnabled() && Config::uiFrontend() === Frontend::Bundled) {
            $this->call('vendor:publish', ['--tag' => 'acl-assets', '--force' => true]);
        }

        $stubs = $this->teamStubs();

        $existing = array_values(array_filter($stubs, fn (string $stub) => $this->alreadyExistingMigrations($stub) !== []));
        $pending = array_values(array_diff($stubs, $existing));
        $publish = ! collect($this->migrationNames())->contains(fn (string $name) => str_ends_with($name, '_create_permission_tables.php'));

        $this->line('');
        $this->info('The ACL setup is going to add the migrations the permission tables need');

        if ($existing && ! $force) {
            $this->line('');

            $this->warn("These migrations already exist and are skipped:\n - " . implode("\n - ", $existing));
        }

        $overwrite = $force ? $existing : [];

        if ($pending === [] && $overwrite === [] && ! $publish) {
            return self::SUCCESS;
        }

        $this->line('');

        if ($pretend) {
            $this->info('These migrations would be written:');

            foreach ([...($publish ? ['create_permission_tables'] : []), ...$pending, ...$overwrite] as $stub) {
                $this->line(' - ' . $stub . (in_array($stub, $overwrite, true) ? ' (overwritten)' : ''));
            }

            return self::SUCCESS;
        }

        // A script passes --force or --no-interaction and is never asked.
        if (! $force && $this->input->isInteractive() && ! $this->confirm('Proceed with the migration creation?', true)) {
            return self::SUCCESS;
        }

        // The permission tables come first: our migrations alter and reference them.
        if ($publish) {
            $this->call('vendor:publish', ['--tag' => 'permission-migrations']);
        }

        // Ours must sort after the permission migration, whatever clock published it.
        $time = collect($this->migrationNames())
            ->map(fn (string $name) => preg_match('/^(\d{4}_\d{2}_\d{2}_\d{6})_/', $name, $match) ? Date::createFromFormat('Y_m_d_His', $match[1]) : null)
            ->filter()
            ->push(now())
            ->max();

        foreach ($pending as $stub) {
            $time = $time->addSecond();

            copy(__DIR__ . "/../../database/migrations/{$stub}.php.stub", $this->getMigrationPath($stub, $time->format('Y_m_d_His')));
        }

        foreach ($overwrite as $stub) {
            foreach ($this->alreadyExistingMigrations($stub) as $name) {
                copy(__DIR__ . "/../../database/migrations/{$stub}.php.stub", database_path("migrations/{$name}"));
            }
        }

        $this->info('Migrations created successfully.');

        $this->line('');

        return self::SUCCESS;
    }

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

    /**
     * Get the migration path.
     */
    protected function getMigrationPath(string $stub, string $date): string
    {
        return database_path("migrations/{$date}_{$stub}.php");
    }
}
