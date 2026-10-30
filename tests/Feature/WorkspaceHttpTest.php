<?php

declare(strict_types=1);

namespace Ipsocode\Acl\Tests\Feature;

use Hypervel\Database\QueryException;
use Hypervel\Permission\Support\Config;
use Hypervel\Support\Facades\DB;
use Ipsocode\Acl\Models\AclTeam;
use Ipsocode\Acl\Models\AclWorkspace;
use Ipsocode\Acl\Tests\WorkspaceTestCase;
use Ipsocode\Acl\Workspaces\Reach;
use Workbench\App\Models\User;
use Workbench\Database\Seeders\AclSeeder;

class WorkspaceHttpTest extends WorkspaceTestCase
{
    protected AclWorkspace $corporate;

    protected AclWorkspace $north;

    protected AclWorkspace $branch;

    protected AclWorkspace $elsewhere;

    protected AclTeam $admins;

    protected function setUpInCoroutine(): void
    {
        $this->corporate = AclWorkspace::create(['name' => 'Corporate', 'kind' => 'corporate']);
        $this->north = AclWorkspace::create(['name' => 'North', 'kind' => 'region', 'parent_id' => $this->corporate->id]);
        $this->branch = AclWorkspace::create(['name' => 'Branch', 'kind' => 'branch', 'parent_id' => $this->north->id]);
        $this->elsewhere = AclWorkspace::create(['name' => 'Elsewhere']);

        (new AclSeeder)->run();

        $this->admins = AclTeam::create(['name' => 'Admins']);
        $this->admins->syncTeamRoles(['manager']);

        // An org role in the home workspace covers the whole tree.
        $admin = User::factory()->create();
        $this->admins->addUser($admin);

        $this->actingAs($admin);
    }

    public function testWorkspacesAreListedBeneathTheOneTheRequestActsIn(): void
    {
        $this->getJson('acl/workspaces')->assertOk()->assertJsonCount(4, 'data');
        $this->getJson('acl/workspaces?under=' . $this->north->id)->assertOk()->assertJsonCount(2, 'data');
        $this->getJson('acl/workspaces?kind=branch')->assertOk()->assertJsonCount(1, 'data')->assertJsonPath('data.0.name', 'Branch');

        $this->withHeader('X-Workspace', (string) $this->north->id)->getJson('acl/workspaces')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAWorkspaceIsShownWithItsAncestors(): void
    {
        $this->getJson("acl/workspaces/{$this->branch->id}")
            ->assertOk()
            ->assertJsonPath('data.name', 'Branch')
            ->assertJsonPath('data.parent_id', $this->north->id)
            ->assertJsonPath('ancestors', [$this->branch->id, $this->north->id, $this->corporate->id]);

        $this->withHeader('X-Workspace', (string) $this->north->id)->getJson("acl/workspaces/{$this->elsewhere->id}")->assertNotFound();
    }

    public function testAWorkspaceIsCreatedBeneathOneTheTeamReaches(): void
    {
        $id = $this->postJson('acl/workspaces', ['name' => 'South', 'parent_id' => $this->corporate->id])->assertCreated()->json('data.id');

        $this->getJson("acl/workspaces/{$id}")->assertOk();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson('acl/workspaces', ['name' => 'Nowhere', 'parent_id' => $this->elsewhere->id])->assertUnprocessable();
        $this->postJson('acl/workspaces', ['name' => 'Missing', 'parent_id' => 9999])->assertUnprocessable();
        $this->postJson('acl/workspaces', [])->assertUnprocessable();
    }

    public function testAWorkspaceIsMovedButNeverBeneathItself(): void
    {
        $this->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Branch', 'parent_id' => $this->corporate->id])->assertOk();
        $this->assertSame($this->corporate->id, $this->branch->refresh()->parent_id);

        $this->putJson("acl/workspaces/{$this->corporate->id}", ['name' => 'Corporate', 'parent_id' => $this->north->id])->assertUnprocessable();
        $this->putJson("acl/workspaces/{$this->north->id}", ['name' => 'North', 'parent_id' => $this->north->id])->assertUnprocessable();
        $this->putJson("acl/workspaces/{$this->north->id}", ['name' => 'North', 'parent_id' => null])->assertOk();
    }

    public function testAWorkspaceWithChildrenCannotBeDeleted(): void
    {
        $this->deleteJson("acl/workspaces/{$this->north->id}")->assertStatus(409);
        $this->deleteJson("acl/workspaces/{$this->branch->id}")->assertNoContent();
        $this->deleteJson("acl/workspaces/{$this->north->id}")->assertNoContent();
    }

    public function testAUserIsGivenAndLosesAnOrgRoleInAWorkspace(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id, 'workspace' => $this->north->id])->assertCreated();
        $this->assertSame(1, DB::table('acl_team_workspace_user')->where('user_id', $user->id)->count());

        // The workspace chain rule is a validation error.
        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id, 'workspace' => $this->branch->id])->assertUnprocessable();

        $this->deleteJson("acl/teams/{$ops->id}/users/{$user->id}?workspace={$this->north->id}")->assertNoContent();
        $this->assertSame(0, DB::table('acl_team_workspace_user')->where('user_id', $user->id)->count());
    }

    public function testTheReachIsTheWorkspaceTheRequestActsInAndBeneath(): void
    {
        $this->getJson('acl/reach')->assertOk()->assertJsonCount(5, 'data');
        $this->withHeader('X-Workspace', (string) $this->north->id)->getJson('acl/reach')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAnOrgRoleBelowHomeActsInItsWorkspace(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $ops->syncTeamRoles(['manager']);
        $user = User::factory()->create();
        $ops->addUser($user, $this->north->id);

        $this->actingAs($user)->getJson('acl/workspaces')->assertOk()->assertJsonCount(2, 'data');
    }

    public function testAUserIsAddedInTheWorkspaceTheRequestActsInUnlessTheCallerCannotReachIt(): void
    {
        $ops = AclTeam::create(['name' => 'Ops']);
        $user = User::factory()->create();

        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson("acl/teams/{$ops->id}/users", ['user_id' => $user->id])->assertCreated();
        $this->assertSame($this->north->id, DB::table('acl_team_workspace_user')->where('user_id', $user->id)->value('workspace_id'));

        $other = User::factory()->create();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson("acl/teams/{$ops->id}/users", ['user_id' => $other->id, 'workspace' => $this->elsewhere->id])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson("acl/teams/{$ops->id}/users", ['user_id' => $other->id, 'workspace' => 0])->assertUnprocessable();
        $this->postJson("acl/teams/{$ops->id}/users", ['user_id' => $other->id, 'workspace' => 9999])->assertUnprocessable();
        $this->assertSame(0, DB::table('acl_team_workspace_user')->where('user_id', $other->id)->count());
    }

    public function testOnlyTheHomeWorkspaceCreatesOrMovesAWorkspaceToTheTop(): void
    {
        $this->postJson('acl/workspaces', ['name' => 'Root'])->assertCreated();

        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson('acl/workspaces', ['name' => 'Root'])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->postJson('acl/workspaces', ['name' => 'Root', 'parent_id' => null])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Branch', 'parent_id' => null])->assertUnprocessable();
        $this->withHeader('X-Workspace', (string) $this->north->id)->putJson("acl/workspaces/{$this->branch->id}", ['name' => 'Renamed'])->assertOk();
        $this->assertSame($this->north->id, $this->branch->refresh()->parent_id);
    }

    public function testAWorkspaceThatDoesNotExistIsRefusedAndNotRemembered(): void
    {
        $this->withHeader('X-Workspace', '987654')->getJson('acl/teams')->assertForbidden();
        $this->assertNull(cache()->get(Reach::CACHE_KEY_PREFIX . '987654'));

        $reach = $this->app->make(Reach::class);

        $this->assertSame([987654], $reach->ancestorsOf(987654));
        $this->assertNull(cache()->get(Reach::CACHE_KEY_PREFIX . '987654'));
        $this->assertSame([$this->north->id, $this->corporate->id], $reach->ancestorsOf($this->north->id));
        $this->assertSame([$this->north->id, $this->corporate->id], cache()->get(Reach::CACHE_KEY_PREFIX . $this->north->id));
    }

    public function testADeleteThatFailsKeepsTheWorkspacesRoles(): void
    {
        $roles = DB::table(Config::rolesTable())->where('workspace_id', $this->north->id)->count();
        $this->assertGreaterThan(0, $roles);

        try {
            $this->north->delete();
            $this->fail('The workspace has others beneath it.');
        } catch (QueryException) {
            $this->assertSame($roles, DB::table(Config::rolesTable())->where('workspace_id', $this->north->id)->count());
        }
    }
}
