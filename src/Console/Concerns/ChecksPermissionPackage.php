<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Console\Concerns;

use Hypervel\Permission\PermissionServiceProvider;
use Hypervel\Support\Facades\Schema;

trait ChecksPermissionPackage
{
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
            return 'The permission package has teams off: run php artisan permission:setup-teams and set permission.teams to true.';
        }

        if ($migrated && ! Schema::hasTable($config->string('permission.table_names.roles'))) {
            return 'The permission tables are not migrated: run php artisan acl:setup, then php artisan migrate.';
        }

        return null;
    }
}
