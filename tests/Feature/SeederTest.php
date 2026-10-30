<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Permission\Models\Permission;
use Hypervel\Permission\Models\Role;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Tests\TestCase;
use Workbench\Database\Seeders\AclSeeder;

class SeederTest extends TestCase
{
    public function testSeedsIdempotentlyWithGlobalRoles(): void
    {
        (new AclSeeder)->run();
        (new AclSeeder)->run();

        $this->assertSame(5, Role::query()->count());
        $this->assertSame(0, Role::query()->whereNotNull('team_id')->count());
        $this->assertSame(['acl.*', 'articles.*', 'articles.delete', 'articles.edit', 'articles.view'], Permission::query()->orderBy('name')->pluck('name')->all());
        $this->assertTrue(Role::findByName('restricted')->hasDeniedPermission('articles.delete'));
    }

    public function testSeedingPrunesOnlyGlobalRolesAndRestoresTheCurrentTeam(): void
    {
        $team = AclTeam::create(['name' => 'Ops']);

        Role::create(['name' => 'local', 'team_id' => $team->id]);
        setPermissionsTeamId(7);

        (new AclSeeder)->run();

        $this->assertSame(7, getPermissionsTeamId());
        $this->assertTrue(Role::query()->where('name', 'local')->exists());
    }

    public function testCreatingATeamCreatesNoRoles(): void
    {
        AclTeam::create(['name' => 'Ops']);

        $this->assertSame(0, Role::query()->count());
    }
}
