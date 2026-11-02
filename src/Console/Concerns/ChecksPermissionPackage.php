<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console\Concerns;

use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Schema;
use SplFileInfo;

trait ChecksPermissionPackage
{
    /**
     * Determine if hypervel/permission is active, reporting what is missing when it is not.
     *
     * An interactive run offers to take the missing step: publish the permission config, turn on
     * its teams, or run acl:setup for the permission migrations. The application's config is read
     * at boot, so the command is run again once a step has changed it. A run under --no-interaction
     * or --pretend is never asked.
     */
    protected function ensurePermissionPackage(bool $migrated = true): bool
    {
        $problem = $this->permissionPackageProblem($migrated);

        if ($problem === null) {
            return true;
        }

        $this->error($problem);

        if ($this->input->isInteractive() && ! ($this->input->hasOption('pretend') && $this->option('pretend'))) {
            $this->offerPermissionSetup();
        }

        return false;
    }

    /**
     * Describe what is missing for hypervel/permission to be active, or null when it is.
     *
     * Pass $migrated false for a command that publishes the migrations, which cannot need them to have run.
     */
    protected function permissionPackageProblem(bool $migrated = true): ?string
    {
        if (! $this->hypervel->providerIsLoaded(PermissionServiceProvider::class)) {
            return 'The permission service provider is not loaded: do not exclude hypervel/permission from package discovery, or register ' . PermissionServiceProvider::class . '.';
        }

        $config = $this->hypervel->get('config');

        if (! is_string($config->get('permission.table_names.roles'))) {
            return 'The permission config has no table names: run php artisan vendor:publish --tag=permission-config.';
        }

        if (! $config->boolean('permission.teams')) {
            return 'The permission package has teams off: set permission.teams to true, then run php artisan permission:setup-teams if the permission tables already exist.';
        }

        if ($migrated && ! Schema::hasTable($config->string('permission.table_names.roles'))) {
            return 'The permission tables are not migrated: run php artisan acl:setup, then php artisan migrate.';
        }

        return null;
    }

    /**
     * Ask to take the step the permission package is missing, and take it on a yes.
     */
    protected function offerPermissionSetup(): void
    {
        if (! $this->hypervel->providerIsLoaded(PermissionServiceProvider::class)) {
            return;
        }

        $config = $this->hypervel->get('config');

        if (! is_string($config->get('permission.table_names.roles')) || ! $config->boolean('permission.teams')) {
            $published = false;

            if (! File::exists(config_path('permission.php'))) {
                if (! $this->confirm('Publish the permission config now?', true)) {
                    return;
                }

                $this->call('vendor:publish', ['--tag' => 'permission-config']);

                $published = true;
            }

            $changed = $published;

            if (! $config->boolean('permission.teams') && $this->confirm('Turn on teams in config/permission.php?', true)) {
                $changed = $this->turnOnPermissionTeams() || $changed;
            }

            if ($changed) {
                $this->info('The config is read at boot: run this command again.');
            }

            return;
        }

        if ($this->permissionMigrationPublished()) {
            $this->info('The permission migration is published: run php artisan migrate, then run this command again.');

            return;
        }

        if ($this->confirm('Run acl:setup now to publish the permission migrations?', true) && $this->publishPermissionMigration()) {
            $this->info('Run php artisan migrate, then run this command again.');
        }
    }

    /**
     * Run acl:setup and determine if it published the permission tables' migration.
     */
    protected function publishPermissionMigration(): bool
    {
        return $this->call('acl:setup') === self::SUCCESS && $this->permissionMigrationPublished();
    }

    /**
     * Determine if the permission tables' migration is in the application's migrations directory.
     */
    protected function permissionMigrationPublished(): bool
    {
        return collect(File::files(database_path('migrations')))
            ->contains(fn (SplFileInfo $file) => str_ends_with($file->getFilename(), '_create_permission_tables.php'));
    }

    /**
     * Set teams to true in the application's permission config, or tell the user to when it is not false there exactly once.
     *
     * Return whether the file was changed.
     */
    protected function turnOnPermissionTeams(): bool
    {
        $contents = File::get(config_path('permission.php'));

        if (preg_match_all("/'teams' => false,/", $contents) !== 1) {
            $this->warn("Set 'teams' => true in config/permission.php: it is not false there exactly once, so it was left as it is.");

            return false;
        }

        File::put(config_path('permission.php'), str_replace("'teams' => false,", "'teams' => true,", $contents));

        $this->info("'teams' was set to true in config/permission.php.");

        return true;
    }
}
