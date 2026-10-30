<?php

declare(strict_types=1);

namespace Ipsocode\Acl;

use Hypervel\Console\Events\ArtisanStarting;
use Hypervel\Console\Events\BeforeHandle;
use Hypervel\Permission\Events\RoleAttachedEvent;
use Hypervel\Permission\Events\RoleDetachedEvent;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\DB;
use Hypervel\Support\Facades\Event;
use Hypervel\Support\Facades\File;
use Hypervel\Support\Facades\Route;
use Hypervel\Support\ServiceProvider;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Console\MakeAclSeederCommand;
use Ipsocode\Acl\Console\SetupCommand;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Contracts\WorkspaceResolver;
use Ipsocode\Acl\Events\OrgRoleAdding;
use Ipsocode\Acl\Http\Middleware\ResolveAcl;
use Ipsocode\Acl\Http\UiAssets;
use Ipsocode\Acl\Listeners\EnsureOrgRolesAreUnrelated;
use Ipsocode\Acl\Permission\TenantPermissionRegistrar;
use Ipsocode\Acl\Support\Config;
use Ipsocode\Acl\Support\Frontend;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Support\Workspaces;
use Ipsocode\Acl\Workspaces\RequestWorkspaceResolver;
use LogicException;
use RuntimeException;
use Symfony\Component\Console\Input\InputOption;

class AclServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/acl.php', 'acl');

        // Unbound in the framework, so this replaces the auto-singleton every caller resolves.
        $this->app->singleton(PermissionRegistrar::class, TenantPermissionRegistrar::class);

        $this->app->singleton(WorkspaceResolver::class, RequestWorkspaceResolver::class);

        // The framework reads the partition whenever it queries or caches; without one set, the home workspace.
        if (Workspaces::enabled()) {
            PermissionRegistrar::resolvePartitionUsing(Workspaces::column(), static fn (): int|string => Workspace::id());
        }
    }

    public function boot(): void
    {
        // Console commands, acl:setup among them, run before teams are configured.
        if (! $this->app->runningInConsole()) {
            $this->assertTeamsConfigured();
        }

        if ($this->app->runningInConsole()) {
            $this->publishes([__DIR__ . '/../config/acl.php' => config_path('acl.php')], 'acl-config');

            $this->publishesMigrations([
                __DIR__ . '/../database/migrations/2026_10_29_000002_create_acl_teams_table.php' => database_path('migrations/2026_10_29_000002_create_acl_teams_table.php'),
                __DIR__ . '/../database/migrations/2026_10_29_000003_create_acl_team_user_table.php' => database_path('migrations/2026_10_29_000003_create_acl_team_user_table.php'),
                __DIR__ . '/../database/migrations/2026_10_29_000004_create_acl_workspaces_table.php' => database_path('migrations/2026_10_29_000004_create_acl_workspaces_table.php'),
                __DIR__ . '/../database/migrations/2026_10_29_000005_create_acl_team_workspace_user_table.php' => database_path('migrations/2026_10_29_000005_create_acl_team_workspace_user_table.php'),
            ], 'acl-migrations');

            $this->publishes([__DIR__ . '/../dist' => public_path('vendor/acl')], 'acl-assets');
            $this->publishes($this->reactPages(), 'acl-react');

            $this->commands([MakeAclSeederCommand::class, SetupCommand::class]);

            $this->registerConsoleOptions();
        }

        // With no team model of its own, the application uses the package's, whose table this creates.
        if (Teams::usesPackageModel()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2026_10_29_000002_create_acl_teams_table.php');
        }

        if (Workspaces::enabled()) {
            if (Workspaces::usesPackageModel()) {
                $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2026_10_29_000004_create_acl_workspaces_table.php');
            }

            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2026_10_29_000005_create_acl_team_workspace_user_table.php');
        } else {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2026_10_29_000003_create_acl_team_user_table.php');
        }

        $this->registerListeners();

        if ($this->app->get('config')->get('acl.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/acl.php');
        }

        if (Config::uiEnabled()) {
            $this->registerUi();
        }
    }

    /**
     * Register the management UI: its views and its routes, under their own path and middleware.
     */
    protected function registerUi(): void
    {
        $config = $this->app->get('config');

        $this->loadViewsFrom(__DIR__ . '/../resources/views', 'acl');

        // Hashed here, before the workers fork, so no request reads the bundle.
        $this->app->instance(UiAssets::class, new UiAssets(Config::uiFrontend() === Frontend::Bundled ? (hash_file('xxh128', __DIR__ . '/../dist/app.js') ?: throw new RuntimeException('Unable to read the ACL UI bundle.')) : ''));

        // ResolveAcl comes first: the permission check reads the team and roles it sets.
        Route::middlewareGroup('acl.ui', [
            ...$config->array('acl.ui.middleware'),
            ResolveAcl::class,
            'permission:' . $config->string('acl.ui.permission'),
        ]);

        Route::domain($config->get('acl.ui.domain'))
            ->middleware('acl.ui')
            ->prefix($config->string('acl.ui.path'))
            ->name('acl.ui.')
            ->group(__DIR__ . '/../routes/web.php');
    }

    /**
     * Require the framework's teams feature.
     *
     * @throws LogicException
     */
    public function assertTeamsConfigured(): void
    {
        $config = $this->app->get('config');

        if (! $config->boolean('permission.teams')) {
            throw new LogicException('ipsocode/hypervel-acl needs permission.teams set to true.');
        }
    }

    /**
     * Add the global --team and --workspace options, the way --env works.
     */
    protected function registerConsoleOptions(): void
    {
        Event::listen(ArtisanStarting::class, static function (ArtisanStarting $event): void {
            $event->artisan->getDefinition()->addOption(new InputOption(
                'team',
                null,
                InputOption::VALUE_REQUIRED,
                'The permissions team to run the command as',
            ));

            $event->artisan->getDefinition()->addOption(new InputOption(
                'workspace',
                null,
                InputOption::VALUE_REQUIRED,
                'The workspace to run the command in',
            ));
        });

        // BeforeHandle runs inside the command's own coroutine, where Context writes are visible.
        Event::listen(BeforeHandle::class, static function (BeforeHandle $event): void {
            if ($event->input->hasParameterOption('--team')) {
                setPermissionsTeamId($event->input->getParameterOption('--team', null, true));
            }

            if ($event->input->hasParameterOption('--workspace')) {
                Workspace::set($event->input->getParameterOption('--workspace', null, true));
            }
        });
    }

    protected function registerListeners(): void
    {
        $app = $this->app;

        // Before a user is given an org role, it must not reference one the user holds.
        Event::listen(OrgRoleAdding::class, static function (OrgRoleAdding $event) use ($app): void {
            $app->make(EnsureOrgRolesAreUnrelated::class)->handle($event);
        });

        // A role change on a team marks its memberships and those of the teams that inherit it;
        // their members are rebuilt on their next request. The events may fire inside a transaction.
        foreach ([RoleAttachedEvent::class, RoleDetachedEvent::class] as $event) {
            Event::listen($event, static function (RoleAttachedEvent|RoleDetachedEvent $event) use ($app): void {
                $team = $event->model;

                if ($team instanceof Team) {
                    DB::afterCommit(static fn () => $app->make(AccessSync::class)->markChanged($team));
                }
            });
        }
    }

    /**
     * Get the files the acl-react tag publishes: the pages and what they share, without the bundle's entry or the tests.
     *
     * @return array<string, string>
     */
    protected function reactPages(): array
    {
        $root = __DIR__ . '/../resources/js';
        $files = [];

        foreach (File::allFiles($root) as $file) {
            $path = $file->getPathname();

            if ($file->getFilename() !== 'app.tsx' && ! str_contains($path, '.test.')) {
                $files[$path] = resource_path('js/pages/acl/' . substr($path, strlen($root) + 1));
            }
        }

        return $files;
    }
}
