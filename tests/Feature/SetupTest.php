<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

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
            $this->assertStringNotContainsString('_create_acl_teams_table.php', $created);

            // Everything exists now: the stubs are skipped and nothing is created again.
            $this->artisan('acl:setup')->expectsOutputToContain('already exist and are skipped')->assertExitCode(0);
            $this->assertCount(3, array_diff(glob(database_path('migrations/*')), $before));
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
        config(['permission.column_names.team_foreign_key' => 'missing_team_id']);

        $this->artisan('acl:setup')->expectsOutputToContain('permission:setup-teams')->assertExitCode(1);
    }
}
