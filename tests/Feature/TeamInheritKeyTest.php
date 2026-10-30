<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Contracts\Foundation\Application;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Tests\TestCase;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class TeamInheritKeyTest extends TestCase
{
    protected function defineEnvironment(Application $app): void
    {
        parent::defineEnvironment($app);

        $app->get('config')->set('acl.column_names.teams_inherit_key', 'parent_team_id');
    }

    protected function setUpInCoroutine(): void
    {
        (new AclSeeder)->run();
    }

    public function testTheApiUsesTheConfiguredInheritColumn(): void
    {
        $admins = AclTeam::create(['name' => 'Admins']);
        $other = AclTeam::create(['name' => 'Other']);
        $admin = User::factory()->create();

        $admins->syncTeamRoles(['manager']);
        $admins->addUser($admin);

        $this->actingAs($admin);

        $this->postJson('acl/teams', ['name' => 'New', 'parent_team_id' => $other->id])
            ->assertCreated()
            ->assertJsonPath('data.parent_team_id', $other->id);

        $this->putJson("acl/teams/{$admins->id}", ['name' => 'Admins', 'parent_team_id' => $other->id])
            ->assertOk()
            ->assertJsonPath('data.parent_team_id', $other->id);

        $this->putJson("acl/teams/{$other->id}", ['name' => 'Other', 'parent_team_id' => $admins->id])->assertUnprocessable();
        $this->postJson('acl/teams', ['name' => 'Missing', 'parent_team_id' => 99999])->assertUnprocessable();
    }
}
