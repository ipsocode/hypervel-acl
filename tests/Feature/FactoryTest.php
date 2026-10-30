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

    public function testInTeamLeavesTheFactoryItWasCalledOnUntouched(): void
    {
        $first = AclTeam::create(['name' => 'Ops']);
        $second = AclTeam::create(['name' => 'Sales']);
        $first->syncTeamRoles(['editor']);
        $second->syncTeamRoles(['editor']);

        $base = User::factory();
        $a = $base->inTeam($first)->member()->create();
        $b = $base->inTeam($second)->member()->create();

        $this->assertSame([$a->id], $first->memberKeys());
        $this->assertSame([$b->id], $second->memberKeys());
    }

    public function testStatesNeedATeam(): void
    {
        $this->expectException(LogicException::class);

        User::factory()->role('viewer')->create();
    }
}
