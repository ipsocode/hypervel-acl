<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\Schema\Blueprint;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\Fixtures\AppTeam;
use Ipsocode\Acl\Tests\TestCase;
use RuntimeException;

class TeamStubsTest extends TestCase
{
    protected function appTeams(): void
    {
        config(['acl.models.team' => AppTeam::class, 'acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => 'tenant']);

        Schema::create('app_teams', fn (Blueprint $table) => $table->id() && $table->string('name'));
    }

    protected function stub(string $name): object
    {
        return require __DIR__ . "/../../database/migrations/{$name}.php.stub";
    }

    public function testTheConfigNamesTheTeamModel(): void
    {
        $this->assertSame('acl_teams', (new AclTeam)->getTable());
        $this->assertTrue(Teams::usesPackageModel());

        config(['acl.models.team' => null]);
        $this->assertSame(AclTeam::class, Teams::model());
        $this->assertTrue(Teams::usesPackageModel());

        config(['acl.models.team' => AppTeam::class]);
        $this->assertFalse(Teams::usesPackageModel());
    }

    public function testInheritanceColumnReferencesTheSameTable(): void
    {
        $this->assertTrue(Schema::hasColumn('acl_teams', 'inherit_id'));

        $parent = AclTeam::create(['name' => 'Parent']);
        $child = AclTeam::create(['name' => 'Child', 'inherit_id' => $parent->id]);

        $this->assertSame($parent->id, $child->fresh()->inherit_id);

        $foreign = Schema::getForeignKeys('acl_teams');
        $this->assertSame('acl_teams', $foreign[0]['foreign_table']);
        $this->assertSame(['inherit_id'], $foreign[0]['columns']);
    }

    public function testTheInheritanceStubAddsTheColumnToTheApplicationsTeamTableOnce(): void
    {
        $this->appTeams();

        $this->stub('add_team_inheritance')->up();
        $this->stub('add_team_inheritance')->up();
        $this->stub('add_team_inheritance')->down();

        $this->assertTrue(Schema::hasColumn('app_teams', 'inherit_id'));
        $this->assertSame('app_teams', Schema::getForeignKeys('app_teams')[0]['foreign_table']);
    }

    public function testTheTenantStubAddsTheColumnToThePermissionAndTeamTables(): void
    {
        $this->appTeams();

        $this->stub('add_tenant_column')->up();
        $this->stub('add_tenant_column')->up();
        $this->stub('add_tenant_column')->down();

        foreach (['roles', 'permissions', 'model_has_roles', 'model_has_permissions', 'role_has_permissions', 'app_teams'] as $table) {
            $this->assertTrue(Schema::hasColumn($table, 'tenant'), $table);
        }
    }

    public function testTenantsOnWithoutAColumnFailLikeTheFrameworkDoes(): void
    {
        config(['acl.tenant' => true, 'acl.column_names.tenant_foreign_key' => null]);

        try {
            Teams::tenantColumn();
            $this->fail('A missing tenant column was accepted.');
        } catch (RuntimeException $exception) {
            $this->assertSame('Error: tenant_foreign_key on config/acl.php not loaded. Run [php artisan config:clear] and try again.', $exception->getMessage());
        }

        config(['acl.column_names.tenant_foreign_key' => '']);

        $this->expectException(RuntimeException::class);
        $this->stub('add_tenant_column')->up();
    }

    public function testTheTenantStubDoesNothingWithTenantsOff(): void
    {
        $this->appTeams();
        config(['acl.tenant' => false]);

        $this->stub('add_tenant_column')->up();

        foreach (['roles', 'app_teams'] as $table) {
            $this->assertFalse(Schema::hasColumn($table, 'tenant'), $table);
        }
    }
}
