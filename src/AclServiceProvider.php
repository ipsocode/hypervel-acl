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
use Hypervel\Support\ServiceProvider;
use Ipsocode\Acl\Access\AccessSync;
use Ipsocode\Acl\Console\MakeAclSeederCommand;
use Ipsocode\Acl\Console\SetupCommand;
use Ipsocode\Acl\Contracts\Team;
use Ipsocode\Acl\Permission\TenantPermissionRegistrar;
use Ipsocode\Acl\Support\Teams;
use LogicException;
use Symfony\Component\Console\Input\InputOption;

class AclServiceProvider extends ServiceProvider
{
    public function register(): void
    {
        $this->mergeConfigFrom(__DIR__ . '/../config/acl.php', 'acl');

        // Unbound in the framework, so this replaces the auto-singleton every caller resolves.
        $this->app->singleton(PermissionRegistrar::class, TenantPermissionRegistrar::class);
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
            ], 'acl-migrations');

            $this->commands([MakeAclSeederCommand::class, SetupCommand::class]);

            $this->registerConsoleOptions();
        }

        // With no team model of its own, the application uses the package's, whose table this creates.
        if (Teams::usesPackageModel()) {
            $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2026_10_29_000002_create_acl_teams_table.php');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations/2026_10_29_000003_create_acl_team_user_table.php');

        $this->registerListeners();

        if ($this->app->get('config')->get('acl.routes.enabled')) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/acl.php');
        }
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
     * Add the global --team option, the way --env works.
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
        });

        // BeforeHandle runs inside the command's own coroutine, where Context writes are visible.
        Event::listen(BeforeHandle::class, static function (BeforeHandle $event): void {
            if ($event->input->hasParameterOption('--team')) {
                setPermissionsTeamId($event->input->getParameterOption('--team', null, true));
            }
        });
    }

    protected function registerListeners(): void
    {
        $app = $this->app;

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
}
