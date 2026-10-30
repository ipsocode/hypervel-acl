<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests;

use Hypervel\Contracts\Foundation\Application;
use Ipsocode\Acl\AclServiceProvider;
use Ipsocode\Acl\Models\AclWorkspace;

use function Hypervel\Testbench\default_migration_path;

/**
 * A test case with workspaces on: the permission tables are partitioned by workspace_id.
 */
abstract class WorkspaceTestCase extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $config = $app->get('config');

        $config->set('acl.workspace', true);
        $config->set('acl.column_names.workspace_foreign_key', 'workspace_id');
        $config->set('acl.models.workspace', AclWorkspace::class);

        // Providers register before this runs; a real application's config is loaded before them.
        (new AclServiceProvider($app))->register();
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([default_migration_path(), __DIR__ . '/Fixtures/workspace-migrations']);
    }
}
