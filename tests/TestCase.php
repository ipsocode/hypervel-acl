<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Database\Eloquent\Model;
use Hypervel\Foundation\Testing\RefreshDatabase;
use Hypervel\Http\Request;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Testbench\Concerns\WithWorkbench;
use Hypervel\Testbench\TestCase as BaseTestCase;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\Permission;
use Ipsocode\Acl\Models\Role;
use ReflectionClass;
use Symfony\Component\HttpFoundation\Response;

use function Hypervel\Testbench\default_migration_path;

abstract class TestCase extends BaseTestCase
{
    use RefreshDatabase;
    use WithWorkbench;

    protected function defineEnvironment(Application $app): void
    {
        $config = $app->get('config');

        $config->set('permission.events_enabled', true);
        $config->set('permission.teams', true);
        $config->set('permission.models.team', AclTeam::class);
        $config->set('permission.models.role', Role::class);
        $config->set('permission.models.permission', Permission::class);
        $config->set('acl.routes.enabled', true);
        $config->set('acl.routes.middleware', ['auth:web']);
        $config->set('permission.enable_wildcard_permission', true);
    }

    /**
     * Run a request as the user, with the test's session, through ResolveAcl, which brings a
     * stale member up to date.
     */
    protected function nextRequest(Model $user): void
    {
        $request = Request::create('/');
        $request->setUserResolver(fn () => $user);
        $request->setHypervelSession($this->app->make('session')->driver());

        $this->app->make(ResolveAcl::class)->handle($request, fn (): Response => new Response);
    }

    protected function defineDatabaseMigrations(): void
    {
        $this->loadMigrationsFrom([
            default_migration_path(),
            // The framework's own permission tables; the package loads its team table itself.
            dirname((new ReflectionClass(PermissionRegistrar::class))->getFileName()) . '/../database/migrations',
        ]);
    }
}
