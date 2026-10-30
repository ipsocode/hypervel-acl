<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Hypervel\Permission\PermissionRegistrar;
use Hypervel\Support\Facades\Schema;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Support\Teams;
use Ipsocode\Acl\Tests\TestCase;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class PivotKeysTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.column_names.team_pivot_key', 'group_id');
        $app->get('config')->set('acl.column_names.user_pivot_key', 'member_id');
    }

    public function testTheMembershipColumnsTakeTheConfiguredNames(): void
    {
        $this->assertTrue(Schema::hasColumns(Teams::ORGANIZATIONS_TABLE, ['group_id', 'member_id']));
        $this->assertFalse(Schema::hasColumn(Teams::ORGANIZATIONS_TABLE, 'team_id'));

        (new AclSeeder)->run();

        $ops = AclTeam::create(['name' => 'Ops']);
        $ops->syncTeamRoles(['viewer']);
        $user = User::factory()->create();

        $ops->addUser($user);
        $this->assertSame([$user->id], $ops->memberKeys());

        $ops->syncTeamRoles(['editor']);
        $this->nextRequest($user);

        $this->app->make(PermissionRegistrar::class)->setPermissionsTeamId($ops);
        $this->assertSame(['editor'], User::find($user->id)->getRoleNames()->all());

        $ops->removeUser($user);
        $this->assertSame([], $ops->memberKeys());
    }
}
