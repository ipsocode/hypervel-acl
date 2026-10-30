<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Migrations\Migrator;
use Ipsocode\Acl\AclServiceProvider;
use Ipsocode\Acl\Database\Seeders\AclSeeder as BaseSeeder;
use Ipsocode\Acl\Tests\Fixtures\PlainRole;
use Ipsocode\Acl\Tests\TestCase;
use LogicException;
use Workbench\Database\Seeders\AclSeeder;

class SupportTest extends TestCase
{
    public function testRoleDefaults(): void
    {
        $this->assertSame('web', PlainRole::Plain->guard());
        $this->assertSame([], PlainRole::Plain->deniedPermissions());
        $this->assertSame('', PlainRole::Plain->description());
        $this->assertNull(PlainRole::Plain->mirrors());
        $this->assertInstanceOf(BaseSeeder::class, new AclSeeder);
    }

    public function testMakeAclSeederWritesTheStub(): void
    {
        $path = database_path('seeders/TmpAclSeeder.php');

        try {
            $this->artisan('acl:make-seeder', ['name' => 'TmpAclSeeder'])->assertExitCode(0);
            $this->assertStringContainsString('extends BaseAclSeeder', file_get_contents($path));
        } finally {
            @unlink($path);
        }
    }

    public function testBootNeedsTeamsButNotATeamModel(): void
    {
        config(['permission.models.team' => null, 'acl.models.team' => null]);
        (new AclServiceProvider($this->app))->assertTeamsConfigured();

        config(['permission.teams' => false]);

        $this->expectException(LogicException::class);
        (new AclServiceProvider($this->app))->assertTeamsConfigured();
    }

    public function testThePackageLoadsTheTeamTableMigrationForItsOwnTeamModel(): void
    {
        $paths = $this->app->make(Migrator::class)->paths();

        $this->assertContains(realpath(__DIR__ . '/../../database/migrations/2026_10_29_000002_create_acl_teams_table.php'), array_map('realpath', $paths));
    }

    public function testAWebBootAlsoNeedsTeams(): void
    {
        config(['permission.teams' => false]);
        $this->app->setRunningInConsole(false);

        try {
            $this->expectException(LogicException::class);
            (new AclServiceProvider($this->app))->boot();
        } finally {
            $this->app->setRunningInConsole(true);
        }
    }

    public function testTheTeamOptionSetsTheCurrentTeamOfACommand(): void
    {
        $this->artisan('permission:show', ['--team' => '3'])->assertExitCode(0);
    }
}
