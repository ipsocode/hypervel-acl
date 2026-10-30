<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Permission\PermissionRegistrar;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Tests\TestCase;
use LogicException;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class FactoryTest extends TestCase
{
    protected function setUpInCoroutine(): void
    {
        (new AclSeeder)->run();
    }

    public function testRoleAssignsDirectlyUnderTheTeam(): void
    {
        $team = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->inTeam($team)->role('viewer')->create();

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($team);

        $this->assertTrue(User::find($user->id)->hasRole('viewer'));
    }

    public function testMemberAddsTheUserToTheTeam(): void
    {
        $team = AclTeam::create(['name' => 'Ops']);
        $team->syncTeamRoles(['editor']);

        $user = User::factory()->inTeam($team)->member()->create();

        $this->assertSame([$user->id], $team->memberKeys());
    }

    public function testStatesNeedATeam(): void
    {
        $this->expectException(LogicException::class);

        User::factory()->role('viewer')->create();
    }
}
