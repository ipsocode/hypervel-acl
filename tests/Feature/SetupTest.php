<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Permission\PermissionServiceProvider;
use Ipsocode\Acl\Tests\TestCase;

class SetupTest extends TestCase
{
    public function testSetupPublishesThePermissionMigration(): void
    {
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'yes')->assertExitCode(0);

            $created = array_map('basename', array_diff(glob(database_path('migrations/*')), $before));
            sort($created);
            // The package's team table is a migration the package loads itself.
            $this->assertCount(1, $created);
            $this->assertStringEndsWith('_create_permission_tables.php', $created[0]);

            // Everything exists now: nothing is created or published again.
            $this->artisan('acl:setup')->assertExitCode(0);
            $this->assertCount(1, array_diff(glob(database_path('migrations/*')), $before));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupSkipsPublishingWhenThePermissionMigrationExists(): void
    {
        config(['acl.models.team' => \Ipsocode\Acl\Tests\Fixtures\AppTeam::class]);
        $before = glob(database_path('migrations/*'));
        $existing = database_path('migrations/2099_01_01_000000_create_permission_tables.php');
        file_put_contents($existing, '<?php');

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'yes')->assertExitCode(0);

            $created = array_map('basename', array_diff(glob(database_path('migrations/*')), $before, [$existing]));
            $this->assertCount(1, $created);
            $this->assertStringStartsWith('2099_01_01_000001_', array_values($created)[0]);
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupCanBeDeclinedAndLeavesTeamsOutByDefault(): void
    {
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'no')->assertExitCode(0);
            $this->assertSame($before, glob(database_path('migrations/*')));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupGeneratesTheInheritanceAndTenantStubsForAnApplicationTeamModel(): void
    {
        config(['acl.models.team' => \Ipsocode\Acl\Tests\Fixtures\AppTeam::class, 'acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => 'tenant']);
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'yes')->assertExitCode(0);

            $created = implode(' ', array_map('basename', array_diff(glob(database_path('migrations/*')), $before)));
            $this->assertStringContainsString('_add_team_inheritance.php', $created);
            $this->assertStringContainsString('_add_tenant_column.php', $created);
            $this->assertStringContainsString('_lead_tenant_unique_keys.php', $created);
            $this->assertStringNotContainsString('_create_acl_teams_table.php', $created);

            // Everything exists now: the stubs are skipped and nothing is created again.
            $this->artisan('acl:setup')->expectsOutputToContain('already exist and are skipped')->assertExitCode(0);
            $this->assertCount(4, array_diff(glob(database_path('migrations/*')), $before));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupAddsOnlyTheTenantMigrationForThePackageTeamModel(): void
    {
        config(['acl.models.team' => null, 'acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => 'tenant']);
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'yes')->assertExitCode(0);

            $created = implode(' ', array_map('basename', array_diff(glob(database_path('migrations/*')), $before)));
            $this->assertStringNotContainsString('_add_team_inheritance.php', $created);
            $this->assertStringContainsString('_add_tenant_column.php', $created);
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupAsksForTeamColumnsOnExistingPermissionTables(): void
    {
        $this->app->make(PermissionRegistrar::class)->teamsKey = 'missing_team_id';

        $this->artisan('acl:setup')->expectsOutputToContain('permission:setup-teams')->assertExitCode(1);
    }

    public function testSetupFailsWithAnActionableMessageWhenThePermissionPackageIsNotActive(): void
    {
        config(['permission.teams' => false]);
        $this->artisan('acl:setup')
            ->expectsOutputToContain('set permission.teams to true, then run php artisan permission:setup-teams')
            ->expectsConfirmation('Publish the permission config now?', 'no')
            ->assertExitCode(1);

        config(['permission.teams' => true, 'permission.table_names.roles' => null]);
        $this->artisan('acl:setup', ['--no-interaction' => true])->expectsOutputToContain('--tag=permission-config')->assertExitCode(1);

        config(['permission.table_names.roles' => 'roles']);
        (function (): void {
            unset($this->loadedProviders[PermissionServiceProvider::class]);
        })->call($this->app);
        $this->artisan('acl:setup')->expectsOutputToContain('package discovery')->assertExitCode(1);
    }

    public function testPretendListsTheMigrationsAndWritesNothing(): void
    {
        config(['acl.models.team' => \Ipsocode\Acl\Tests\Fixtures\AppTeam::class]);
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup', ['--pretend' => true])
                ->expectsOutputToContain('create_permission_tables')
                ->expectsOutputToContain('add_team_inheritance')
                ->assertExitCode(0);

            $this->assertSame($before, glob(database_path('migrations/*')));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testForceOverwritesTheGeneratedMigrationsWithoutAsking(): void
    {
        config(['acl.models.team' => \Ipsocode\Acl\Tests\Fixtures\AppTeam::class]);
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup')->expectsConfirmation('Proceed with the migration creation?', 'yes')->assertExitCode(0);

            $generated = array_values(array_filter(array_diff(glob(database_path('migrations/*')), $before), fn (string $path) => str_ends_with($path, '_add_team_inheritance.php')));
            $this->assertCount(1, $generated);
            file_put_contents($generated[0], '<?php // edited');

            $this->artisan('acl:setup', ['--pretend' => true, '--force' => true])->expectsOutputToContain('(overwritten)')->assertExitCode(0);
            $this->assertSame('<?php // edited', file_get_contents($generated[0]));

            $this->artisan('acl:setup', ['--force' => true])->assertExitCode(0);
            $this->assertStringNotContainsString('// edited', file_get_contents($generated[0]));
            $this->assertCount(2, array_diff(glob(database_path('migrations/*')), $before));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupNeverPromptsUnderNoInteraction(): void
    {
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:setup', ['--no-interaction' => true])->assertExitCode(0);

            $this->assertCount(1, array_diff(glob(database_path('migrations/*')), $before));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupOffersToPublishTheUnpublishedPermissionConfig(): void
    {
        config(['permission.table_names.roles' => null]);
        $path = config_path('permission.php');
        $existed = file_exists($path);
        $original = $existed ? file_get_contents($path) : null;

        try {
            $this->artisan('acl:setup')
                ->expectsConfirmation('Publish the permission config now?', 'no')
                ->assertExitCode(1);

            $this->artisan('acl:setup', ['--force' => true, '--no-interaction' => true])->assertExitCode(1);

            $this->artisan('acl:setup')
                ->expectsConfirmation('Publish the permission config now?', 'yes')
                ->expectsOutputToContain('run this command again')
                ->assertExitCode(1);
            $this->assertFileExists($path);
        } finally {
            $existed ? file_put_contents($path, $original) : @unlink($path);
        }
    }

    public function testSetupAsksForTheKeyTypeAndSetsItInTheAclConfig(): void
    {
        $path = config_path('acl.php');
        $original = is_file($path) ? file_get_contents($path) : null;
        $before = glob(database_path('migrations/*'));

        try {
            // The permission tables are not migrated for this run.
            config(['permission.table_names.permissions' => 'missing_table']);

            $this->artisan('acl:setup')
                ->expectsChoice('Which key type should the package\'s tables use?', 'uuid', ['id', 'uuid', 'ulid'])
                ->expectsConfirmation('Proceed with the migration creation?', 'no')
                ->expectsOutputToContain("'key_type' was set to 'uuid'")
                ->assertExitCode(0);
            $this->assertStringContainsString("'key_type' => 'uuid',", file_get_contents($path));
            $this->assertSame('uuid', config('acl.key_type'));

            // A key type already written to the file is left alone and the user told.
            config(['acl.key_type' => null]);
            $this->artisan('acl:setup')
                ->expectsChoice('Which key type should the package\'s tables use?', 'ulid', ['id', 'uuid', 'ulid'])
                ->expectsConfirmation('Proceed with the migration creation?', 'no')
                ->expectsOutputToContain("Set 'key_type' => 'ulid'")
                ->assertExitCode(0);
            $this->assertStringContainsString("'key_type' => 'uuid',", file_get_contents($path));

            // A script, a set config and migrated tables are never asked.
            $this->artisan('acl:setup', ['--no-interaction' => true])->assertExitCode(0);
            $this->artisan('acl:setup', ['--pretend' => true])->assertExitCode(0);
        } finally {
            $original === null ? @unlink($path) : file_put_contents($path, $original);
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testSetupPublishesThePermissionConfigAndTurnsOnItsTeams(): void
    {
        config(['permission.teams' => false]);
        $path = config_path('permission.php');

        try {
            $this->artisan('acl:setup')
                ->expectsConfirmation('Publish the permission config now?', 'yes')
                ->expectsConfirmation('Turn on teams in config/permission.php?', 'yes')
                ->expectsOutputToContain("'teams' was set to true")
                ->expectsOutputToContain('run this command again')
                ->assertExitCode(1);
            $this->assertStringContainsString("'teams' => true,", file_get_contents($path));

            // A config already published is not published again, and teams not false there are left alone.
            $this->artisan('acl:setup')
                ->expectsConfirmation('Turn on teams in config/permission.php?', 'yes')
                ->expectsOutputToContain("Set 'teams' => true")
                ->assertExitCode(1);

            file_put_contents($path, str_replace("'teams' => true,", "'teams' => false,", file_get_contents($path)));
            $this->artisan('acl:setup')
                ->expectsConfirmation('Turn on teams in config/permission.php?', 'no')
                ->doesntExpectOutputToContain('run this command again')
                ->assertExitCode(1);
            $this->assertStringContainsString("'teams' => false,", file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testSetupNeverOffersThePermissionSetupUnderPretendOrNoInteraction(): void
    {
        config(['permission.teams' => false]);

        $this->artisan('acl:setup', ['--pretend' => true])->expectsOutputToContain('permission:setup-teams')->assertExitCode(1);
        $this->artisan('acl:setup', ['--no-interaction' => true])->expectsOutputToContain('permission:setup-teams')->assertExitCode(1);

        $this->assertFileDoesNotExist(config_path('permission.php'));
    }

    public function testACommandOffersToRunSetupForUnmigratedPermissionTables(): void
    {
        config(['permission.table_names.roles' => 'unmigrated_roles']);
        $before = glob(database_path('migrations/*'));
        $seeder = database_path('seeders/TmpSetupSeeder.php');

        try {
            $this->artisan('acl:make-seeder', ['name' => 'TmpSetupSeeder'])
                ->expectsConfirmation('Run acl:setup now to publish the permission migrations?', 'no')
                ->assertExitCode(1);
            $this->assertSame($before, glob(database_path('migrations/*')));

            $this->artisan('acl:make-seeder', ['name' => 'TmpSetupSeeder'])
                ->expectsConfirmation('Run acl:setup now to publish the permission migrations?', 'yes')
                ->expectsConfirmation('Proceed with the migration creation?', 'yes')
                ->expectsOutputToContain('php artisan migrate')
                ->assertExitCode(1);
            $this->assertCount(1, array_diff(glob(database_path('migrations/*')), $before));
            $this->assertFileDoesNotExist($seeder);
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
            @unlink($seeder);
        }
    }

    public function testNoMigrateHintIsGivenWhenTheOfferedSetupPublishedNothing(): void
    {
        config(['permission.table_names.roles' => 'unmigrated_roles']);
        $before = glob(database_path('migrations/*'));

        try {
            $this->artisan('acl:make-seeder', ['name' => 'TmpSetupSeeder'])
                ->expectsConfirmation('Run acl:setup now to publish the permission migrations?', 'yes')
                ->expectsConfirmation('Proceed with the migration creation?', 'no')
                ->doesntExpectOutputToContain('Run php artisan migrate, then run this command again.')
                ->assertExitCode(1);
            $this->assertSame($before, glob(database_path('migrations/*')));
        } finally {
            array_map('unlink', array_diff(glob(database_path('migrations/*')), $before));
        }
    }

    public function testAPublishedPermissionMigrationIsToBeMigratedNotPublishedAgain(): void
    {
        config(['permission.table_names.roles' => 'unmigrated_roles']);
        $migration = database_path('migrations/2020_01_01_000000_create_permission_tables.php');
        file_put_contents($migration, '<?php');

        try {
            $this->artisan('acl:make-seeder', ['name' => 'TmpSetupSeeder'])
                ->expectsOutputToContain('The permission migration is published: run php artisan migrate')
                ->assertExitCode(1);
        } finally {
            @unlink($migration);
        }
    }

    public function testTeamsAreNotTurnedOnWhenTheirSettingIsNotInTheFileExactlyOnce(): void
    {
        config(['permission.teams' => false]);
        $path = config_path('permission.php');
        $contents = "<?php\n\nreturn ['teams' => false, 'nested' => ['teams' => false,]];\n";
        file_put_contents($path, $contents);

        try {
            $this->artisan('acl:setup')
                ->expectsConfirmation('Turn on teams in config/permission.php?', 'yes')
                ->expectsOutputToContain('not false there exactly once')
                ->doesntExpectOutputToContain('run this command again')
                ->assertExitCode(1);
            $this->assertSame($contents, file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testNoOfferIsMadeWhenThePermissionProviderIsNotLoaded(): void
    {
        (function (): void {
            unset($this->loadedProviders[PermissionServiceProvider::class]);
        })->call($this->app);

        $this->artisan('acl:make-seeder', ['name' => 'TmpUnloadedSeeder'])->expectsOutputToContain('package discovery')->assertExitCode(1);
    }
}
